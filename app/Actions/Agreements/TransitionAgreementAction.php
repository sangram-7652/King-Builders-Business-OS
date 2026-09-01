<?php

declare(strict_types=1);

namespace App\Actions\Agreements;

use App\Actions\Documents\UploadDocumentAction;
use App\Enums\AgreementStatus;
use App\Enums\DocumentActivityType;
use App\Exceptions\DomainException;
use App\Models\Agreement;
use App\Models\Masters\DocumentType;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Documents\DocumentTimeline;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Drives the remaining agreement transitions (M9): SEND, SIGN (with the signed
 * scan uploaded as a new document version — the prepared PDF is never
 * overwritten), APPROVE (`agreements.approve`) and CANCEL (not after APPROVED).
 *
 * All transitions are idempotent for their target state and validated against
 * {@see AgreementStatus::transitionMap()}.
 */
class TransitionAgreementAction
{
    use RunsInTransaction;

    public function __construct(private readonly UploadDocumentAction $upload) {}

    public function send(Agreement $agreement, User $actor): Agreement
    {
        return $this->move($agreement, AgreementStatus::Sent, $actor, DocumentActivityType::AgreementSent, fn ($a) => $a->forceFill(['sent_at' => now()]));
    }

    public function sign(Agreement $agreement, string $signedBy, UploadedFile $signedFile, User $actor): Agreement
    {
        if (trim($signedBy) === '') {
            throw new DomainException('The signing party must be recorded.');
        }

        return $this->move($agreement, AgreementStatus::Signed, $actor, DocumentActivityType::AgreementSigned, function (Agreement $a) use ($signedBy, $signedFile, $actor): void {
            $type = DocumentType::query()->where('code', 'BOOKING_AGREEMENT')->firstOrFail();
            $document = $this->upload->handle($a->booking()->firstOrFail(), $type, $signedFile, $actor, ['title' => "Signed agreement {$a->agreement_number}"]);

            $a->forceFill([
                'signed_at' => now(),
                'signed_by' => trim($signedBy),
                'signed_recorded_by' => $actor->id,
                'document_id' => $document->id,
            ]);
        });
    }

    public function approve(Agreement $agreement, User $actor): Agreement
    {
        if (! $actor->can('agreements.approve')) {
            throw new DomainException('You are not authorised to approve agreements.');
        }

        return $this->move($agreement, AgreementStatus::Approved, $actor, DocumentActivityType::AgreementApproved, fn ($a) => $a->forceFill(['approved_at' => now(), 'approved_by' => $actor->id]));
    }

    public function cancel(Agreement $agreement, User $actor, ?string $reason = null): Agreement
    {
        return $this->move($agreement, AgreementStatus::Cancelled, $actor, DocumentActivityType::AgreementCancelled, fn ($a) => $a->forceFill([
            'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancellation_reason' => $reason,
        ]));
    }

    private function move(Agreement $agreement, AgreementStatus $target, User $actor, DocumentActivityType $activity, callable $mutate): Agreement
    {
        return $this->transaction(function () use ($agreement, $target, $actor, $activity, $mutate): Agreement {
            /** @var Agreement $locked */
            $locked = Agreement::query()->whereKey($agreement->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === $target) {
                return $locked->load('document.versions', 'booking');
            }

            if (! $locked->canTransitionTo($target)) {
                throw new DomainException("A {$locked->status->label()} agreement cannot move to {$target->label()}.");
            }

            $locked->status = $target;
            $mutate($locked);
            $locked->save();

            DocumentTimeline::record(
                $activity,
                "Agreement {$locked->agreement_number} {$target->label()}.",
                $locked->booking()->first(), null, ['agreement_id' => $locked->id], $actor,
            );

            Log::info('agreement.transitioned', ['agreement_id' => $locked->id, 'to' => $target->value, 'by' => $actor->id]);

            return $locked->load('document.versions', 'booking');
        });
    }
}
