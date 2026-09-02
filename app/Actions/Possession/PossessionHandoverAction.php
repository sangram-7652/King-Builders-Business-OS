<?php

declare(strict_types=1);

namespace App\Actions\Possession;

use App\Actions\Documents\UploadDocumentAction;
use App\Actions\Plots\ChangePlotStatus;
use App\Enums\InspectionStatus;
use App\Enums\PlotStatus;
use App\Enums\PossessionActivityType;
use App\Enums\PossessionCaseStatus;
use App\Enums\PossessionHandoverStatus;
use App\Exceptions\DomainException;
use App\Models\Masters\DocumentType;
use App\Models\PossessionHandover;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Possession\PossessionTimeline;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Physical possession handover to the customer (M10).
 *
 *   READY_FOR_HANDOVER ─▶ HANDOVER ─▶ ACKNOWLEDGEMENT ─▶ COMPLETED
 *
 * `possession.complete`. COMPLETED is terminal and idempotent — a completed
 * handover is never duplicated. Completing moves the possession case to
 * COMPLETED and the plot to POSSESSION_COMPLETED, atomically.
 */
class PossessionHandoverAction
{
    use RunsInTransaction;

    public function __construct(private readonly UploadDocumentAction $upload) {}

    /**
     * @param  array{handover_date: string, received_by: string, receiver_identity?: string|null, receiver_relation?: string|null, remarks?: string|null}  $data
     */
    public function startHandover(PossessionHandover $handover, array $data, User $actor): PossessionHandover
    {
        $this->guard($actor);

        if (trim((string) ($data['received_by'] ?? '')) === '') {
            throw new DomainException('The person taking possession must be recorded.');
        }

        return $this->transaction(function () use ($handover, $data, $actor): PossessionHandover {
            $locked = $this->lock($handover);

            if ($locked->status === PossessionHandoverStatus::Handover) {
                return $locked;
            }

            $this->assertCanMove($locked, PossessionHandoverStatus::Handover);
            $this->assertInspectionOk($locked);

            $locked->forceFill([
                'status' => PossessionHandoverStatus::Handover,
                'handover_date' => $data['handover_date'],
                'received_by' => trim($data['received_by']),
                'receiver_identity' => $data['receiver_identity'] ?? null,
                'receiver_relation' => $data['receiver_relation'] ?? null,
                'remarks' => $data['remarks'] ?? $locked->remarks,
            ])->save();

            $this->log($locked, PossessionActivityType::PossessionHandoverStarted,
                "Possession handover started for {$locked->received_by}.", $actor);

            return $locked;
        });
    }

    public function recordAcknowledgement(PossessionHandover $handover, User $actor, ?UploadedFile $acknowledgement = null): PossessionHandover
    {
        $this->guard($actor);

        return $this->transaction(function () use ($handover, $actor, $acknowledgement): PossessionHandover {
            $locked = $this->lock($handover);

            if ($locked->status === PossessionHandoverStatus::Acknowledgement) {
                return $this->maybeAttachAck($locked, $acknowledgement, $actor);
            }

            $this->assertCanMove($locked, PossessionHandoverStatus::Acknowledgement);

            $locked->forceFill(['status' => PossessionHandoverStatus::Acknowledgement])->save();
            $this->maybeAttachAck($locked, $acknowledgement, $actor);

            $this->log($locked, PossessionActivityType::PossessionAcknowledged,
                "Customer acknowledgement recorded for {$locked->possessionCase->case_number}.", $actor);

            return $locked;
        });
    }

    public function complete(PossessionHandover $handover, User $actor): PossessionHandover
    {
        $this->guard($actor);

        return $this->transaction(function () use ($handover, $actor): PossessionHandover {
            $locked = $this->lock($handover);

            if ($locked->status === PossessionHandoverStatus::Completed) {
                return $locked; // idempotent — never a duplicate completed handover
            }

            $this->assertCanMove($locked, PossessionHandoverStatus::Completed);
            $this->assertInspectionOk($locked);

            $locked->forceFill([
                'status' => PossessionHandoverStatus::Completed,
                'completed_by' => $actor->id,
                'completed_at' => now(),
            ])->save();

            $case = $locked->possessionCase;
            $case->forceFill([
                'status' => PossessionCaseStatus::Completed,
                'completed_at' => now(),
                'completed_by' => $actor->id,
            ])->save();

            $plot = $case->booking->plot;
            if ($plot !== null && $plot->status->canTransitionTo(PlotStatus::PossessionCompleted)) {
                app(ChangePlotStatus::class)->handle($plot, PlotStatus::PossessionCompleted);
            }

            $this->log($locked, PossessionActivityType::PossessionCompleted,
                "Possession of {$case->case_number} completed.", $actor);

            Log::info('possession_handover.completed', ['possession_case_id' => $case->id, 'by' => $actor->id]);

            return $locked->load('possessionCase');
        });
    }

    private function maybeAttachAck(PossessionHandover $handover, ?UploadedFile $file, User $actor): PossessionHandover
    {
        if ($file === null) {
            return $handover;
        }

        $type = DocumentType::query()->where('code', 'POSSESSION_ACK')->firstOrFail();
        $handover->loadMissing('booking');
        $document = $this->upload->handle($handover->booking, $type, $file, $actor, ['title' => 'Possession acknowledgement']);
        $handover->forceFill(['acknowledgement_document_id' => $document->id])->save();

        return $handover;
    }

    private function assertInspectionOk(PossessionHandover $handover): void
    {
        $inspection = $handover->possessionCase->latestInspection;
        if ($inspection !== null && $inspection->status->blocksHandover()) {
            throw new DomainException("The latest site inspection is {$inspection->status->label()} — resolve it before handover.");
        }
        if ($inspection === null || $inspection->status !== InspectionStatus::Passed) {
            throw new DomainException('A passed site inspection is required before handover.');
        }
    }

    private function assertCanMove(PossessionHandover $handover, PossessionHandoverStatus $target): void
    {
        if (! $handover->canTransitionTo($target)) {
            throw new DomainException("A {$handover->status->label()} handover cannot move to {$target->label()}.");
        }
    }

    private function guard(User $actor): void
    {
        if (! $actor->can('possession.complete')) {
            throw new DomainException('You are not authorised to complete a possession handover.');
        }
    }

    private function lock(PossessionHandover $handover): PossessionHandover
    {
        /** @var PossessionHandover $locked */
        $locked = PossessionHandover::query()->whereKey($handover->getKey())->lockForUpdate()->firstOrFail();
        $locked->load('possessionCase.booking.plot', 'possessionCase.latestInspection', 'booking');

        return $locked;
    }

    private function log(PossessionHandover $handover, PossessionActivityType $type, string $description, User $actor): void
    {
        $case = $handover->possessionCase;
        PossessionTimeline::record($type, $description, $case->booking, $case->booking?->plot, null, ['possession_case_id' => $case->id], $actor);
    }
}
