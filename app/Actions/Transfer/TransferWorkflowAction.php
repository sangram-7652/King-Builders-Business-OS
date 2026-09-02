<?php

declare(strict_types=1);

namespace App\Actions\Transfer;

use App\Enums\PossessionActivityType;
use App\Enums\TransferRequestStatus;
use App\Exceptions\DomainException;
use App\Models\TransferRequest;
use App\Models\User;
use App\Services\Transfer\TransferEligibilityService;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Possession\PossessionTimeline;
use Illuminate\Support\Facades\Log;

/**
 * Non-completion transfer transitions (M10): submit, start review, request /
 * clear documents, approve (`transfer.approve`), reject (`transfer.review`),
 * cancel. Every transition is validated against
 * {@see TransferRequestStatus::transitionMap()} and is idempotent for its
 * target. Approval runs the {@see TransferEligibilityService}.
 */
class TransferWorkflowAction
{
    use RunsInTransaction;

    public function __construct(private readonly TransferEligibilityService $eligibility) {}

    public function submit(TransferRequest $transfer, User $actor): TransferRequest
    {
        $this->guard($actor, 'transfer.create');

        return $this->move($transfer, TransferRequestStatus::Submitted, $actor,
            PossessionActivityType::TransferSubmitted, fn ($t) => $t->forceFill(['submitted_at' => now()]));
    }

    public function startReview(TransferRequest $transfer, User $actor): TransferRequest
    {
        $this->guard($actor, 'transfer.review');

        return $this->move($transfer, TransferRequestStatus::UnderReview, $actor,
            PossessionActivityType::TransferUnderReview, fn ($t) => $t->forceFill(['review_started_at' => $t->review_started_at ?? now()]));
    }

    public function requestDocuments(TransferRequest $transfer, User $actor): TransferRequest
    {
        $this->guard($actor, 'transfer.review');

        return $this->move($transfer, TransferRequestStatus::DocumentsPending, $actor, PossessionActivityType::TransferDocumentsPending);
    }

    public function backToReview(TransferRequest $transfer, User $actor): TransferRequest
    {
        $this->guard($actor, 'transfer.review');

        return $this->move($transfer, TransferRequestStatus::UnderReview, $actor, PossessionActivityType::TransferDocumentsVerified);
    }

    /**
     * @param  array{financial_waiver_reason?: string|null}  $data
     */
    public function approve(TransferRequest $transfer, User $actor, array $data = []): TransferRequest
    {
        $this->guard($actor, 'transfer.approve');

        return $this->transaction(function () use ($transfer, $actor, $data): TransferRequest {
            /** @var TransferRequest $locked */
            $locked = TransferRequest::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === TransferRequestStatus::Approved) {
                return $locked;
            }

            if (! $locked->canTransitionTo(TransferRequestStatus::Approved)) {
                throw new DomainException("A {$locked->status->label()} transfer cannot be approved.");
            }

            if (($data['financial_waiver_reason'] ?? null) !== null) {
                $locked->forceFill(['financial_waiver_reason' => trim((string) $data['financial_waiver_reason'])])->save();
            }

            $locked->load('booking');
            $result = $this->eligibility->evaluate($locked);

            if (! $result->eligible) {
                throw new DomainException('Transfer is not eligible for approval: '.implode(' ', $result->reasons()));
            }

            $locked->forceFill([
                'status' => TransferRequestStatus::Approved,
                'approved_at' => now(),
                'approved_by' => $actor->id,
                'financial_snapshot' => $result->toArray(),
            ])->save();

            PossessionTimeline::record(
                PossessionActivityType::TransferApproved,
                "Transfer {$locked->request_number} approved.",
                $locked->booking, $locked->booking?->plot, null,
                ['transfer_request_id' => $locked->id], $actor,
            );

            Log::info('transfer_request.approved', ['transfer_request_id' => $locked->id, 'by' => $actor->id]);

            return $locked;
        });
    }

    public function reject(TransferRequest $transfer, string $reason, User $actor): TransferRequest
    {
        $this->guard($actor, 'transfer.review');

        if (trim($reason) === '') {
            throw new DomainException('A rejection reason is required.');
        }

        return $this->move($transfer, TransferRequestStatus::Rejected, $actor, PossessionActivityType::TransferRejected,
            fn ($t) => $t->forceFill(['rejected_at' => now(), 'rejected_by' => $actor->id, 'rejection_reason' => trim($reason)]));
    }

    public function cancel(TransferRequest $transfer, string $reason, User $actor): TransferRequest
    {
        $this->guard($actor, 'transfer.create');

        return $this->move($transfer, TransferRequestStatus::Cancelled, $actor, PossessionActivityType::TransferCancelled,
            fn ($t) => $t->forceFill(['cancelled_at' => now(), 'cancellation_reason' => $reason ?: null]));
    }

    private function move(TransferRequest $transfer, TransferRequestStatus $target, User $actor, PossessionActivityType $activity, ?callable $mutate = null): TransferRequest
    {
        return $this->transaction(function () use ($transfer, $target, $actor, $activity, $mutate): TransferRequest {
            /** @var TransferRequest $locked */
            $locked = TransferRequest::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === $target) {
                return $locked;
            }

            if (! $locked->canTransitionTo($target)) {
                throw new DomainException("A {$locked->status->label()} transfer cannot move to {$target->label()}.");
            }

            $locked->status = $target;
            if ($mutate !== null) {
                $mutate($locked);
            }
            $locked->save();

            $locked->loadMissing('booking');
            PossessionTimeline::record($activity, "Transfer {$locked->request_number}: {$target->label()}.",
                $locked->booking, $locked->booking?->plot, null, ['transfer_request_id' => $locked->id], $actor);

            return $locked;
        });
    }

    private function guard(User $actor, string $permission): void
    {
        if (! $actor->can($permission)) {
            throw new DomainException('You are not authorised to perform this transfer action.');
        }
    }
}
