<?php

declare(strict_types=1);

namespace App\Actions\Handover;

use App\Actions\Documents\UploadDocumentAction;
use App\Enums\DocumentActivityType;
use App\Enums\HandoverStatus;
use App\Exceptions\DomainException;
use App\Models\DocumentHandover;
use App\Models\Masters\DocumentType;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Documents\DocumentTimeline;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Document handover workflow (M9).
 *
 *   REGISTRY_COMPLETED ─▶ DOCUMENTS_READY ─▶ HANDOVER_SCHEDULED ─▶ HANDED_OVER
 *
 * A completed handover is terminal — re-completing is a no-op; correcting one
 * needs `handover.complete` again via {@see reverse()} first.
 */
class HandoverWorkflowAction
{
    use RunsInTransaction;

    public function __construct(private readonly UploadDocumentAction $upload) {}

    public function markDocumentsReady(DocumentHandover $handover, User $actor): DocumentHandover
    {
        return $this->move($handover, HandoverStatus::DocumentsReady, $actor, DocumentActivityType::HandoverDocumentsReady, fn ($h) => null);
    }

    /**
     * @param  array{scheduled_at: string, notes?: string|null}  $data
     */
    public function schedule(DocumentHandover $handover, array $data, User $actor): DocumentHandover
    {
        return $this->move($handover, HandoverStatus::HandoverScheduled, $actor, DocumentActivityType::HandoverScheduled, fn ($h) => $h->forceFill([
            'scheduled_at' => $data['scheduled_at'],
            'handover_notes' => $data['notes'] ?? $h->handover_notes,
        ]));
    }

    /**
     * @param  array{handover_date: string, received_by: string, notes?: string|null}  $data
     */
    public function complete(DocumentHandover $handover, array $data, User $actor, ?UploadedFile $acknowledgement = null): DocumentHandover
    {
        if (! $actor->can('handover.complete')) {
            throw new DomainException('You are not authorised to complete a handover.');
        }

        if (trim((string) ($data['received_by'] ?? '')) === '') {
            throw new DomainException('The person who received the documents must be recorded.');
        }

        return $this->transaction(function () use ($handover, $data, $actor, $acknowledgement): DocumentHandover {
            $locked = $this->lock($handover);

            if ($locked->status === HandoverStatus::HandedOver) {
                return $locked; // idempotent — no duplicate completed handover
            }

            if (! in_array($locked->status, [HandoverStatus::DocumentsReady, HandoverStatus::HandoverScheduled], true)) {
                throw new DomainException("A {$locked->status->label()} handover cannot be completed.");
            }

            $locked->forceFill([
                'status' => HandoverStatus::HandedOver,
                'handover_date' => $data['handover_date'],
                'received_by' => trim($data['received_by']),
                'handover_notes' => $data['notes'] ?? $locked->handover_notes,
                'completed_by' => $actor->id,
                'completed_at' => now(),
            ])->save();

            if ($acknowledgement !== null) {
                $type = DocumentType::query()->where('code', 'HANDOVER_ACK')->firstOrFail();
                $document = $this->upload->handle($locked->booking, $type, $acknowledgement, $actor, ['title' => 'Handover acknowledgement']);
                $locked->forceFill(['document_id' => $document->id])->save();
            }

            DocumentTimeline::record(
                DocumentActivityType::HandoverCompleted,
                "Documents handed over to {$locked->received_by} on ".$locked->handover_date->format('d M Y').'.',
                $locked->booking, null, ['handover_id' => $locked->id], $actor,
            );

            Log::info('handover.completed', ['handover_id' => $locked->id, 'by' => $actor->id]);

            return $locked->load('document.versions', 'booking');
        });
    }

    public function reverse(DocumentHandover $handover, string $reason, User $actor): DocumentHandover
    {
        if (! $actor->can('handover.complete')) {
            throw new DomainException('You are not authorised to reverse a handover.');
        }

        if (trim($reason) === '') {
            throw new DomainException('A reversal reason is required.');
        }

        return $this->transaction(function () use ($handover, $reason, $actor): DocumentHandover {
            $locked = $this->lock($handover);

            if ($locked->status !== HandoverStatus::HandedOver) {
                throw new DomainException('Only a completed handover can be reversed.');
            }

            $locked->forceFill([
                'status' => HandoverStatus::DocumentsReady,
                'reversed_by' => $actor->id,
                'reversed_at' => now(),
                'reversal_reason' => trim($reason),
            ])->save();

            DocumentTimeline::record(
                DocumentActivityType::HandoverReversed,
                'Handover reversed: '.trim($reason),
                $locked->booking, null, ['handover_id' => $locked->id], $actor,
            );

            return $locked;
        });
    }

    private function move(DocumentHandover $handover, HandoverStatus $target, User $actor, DocumentActivityType $activity, callable $mutate): DocumentHandover
    {
        if (! $actor->can('handover.create')) {
            throw new DomainException('You are not authorised to manage handovers.');
        }

        return $this->transaction(function () use ($handover, $target, $actor, $activity, $mutate): DocumentHandover {
            $locked = $this->lock($handover);

            if ($locked->status === $target) {
                return $locked;
            }

            if (! $locked->canTransitionTo($target)) {
                throw new DomainException("A {$locked->status->label()} handover cannot move to {$target->label()}.");
            }

            $locked->status = $target;
            $mutate($locked);
            $locked->save();

            DocumentTimeline::record($activity, "Handover: {$target->label()}.", $locked->booking, null, ['handover_id' => $locked->id], $actor);

            return $locked;
        });
    }

    private function lock(DocumentHandover $handover): DocumentHandover
    {
        /** @var DocumentHandover $locked */
        $locked = DocumentHandover::query()->whereKey($handover->getKey())->lockForUpdate()->firstOrFail();
        $locked->load('booking');

        return $locked;
    }
}
