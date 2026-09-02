<?php

declare(strict_types=1);

namespace App\Actions\Possession;

use App\Enums\PossessionActivityType;
use App\Enums\PossessionCaseStatus;
use App\Enums\PossessionHandoverStatus;
use App\Exceptions\DomainException;
use App\Models\PossessionCase;
use App\Models\PossessionHandover;
use App\Models\User;
use App\Services\Possession\PossessionChecklistService;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Possession\PossessionTimeline;
use Illuminate\Support\Facades\Log;

/**
 * The possession-case transitions that are not scheduling / inspection (M10):
 * move to READY_FOR_HANDOVER (opens the handover), put on hold / resume, and
 * cancel. Each is idempotent for its target state.
 */
class PossessionCaseWorkflowAction
{
    use RunsInTransaction;

    public function __construct(private readonly PossessionChecklistService $checklist) {}

    public function markReadyForHandover(PossessionCase $case, User $actor): PossessionCase
    {
        if (! $actor->can('possession.complete')) {
            throw new DomainException('You are not authorised to progress this possession case.');
        }

        return $this->transaction(function () use ($case, $actor): PossessionCase {
            $locked = $this->lock($case);

            if ($locked->status === PossessionCaseStatus::ReadyForHandover) {
                return $locked->load('handover');
            }

            if (! in_array($locked->status, [PossessionCaseStatus::Scheduled, PossessionCaseStatus::Inspection], true)) {
                throw new DomainException("A {$locked->status->label()} possession case cannot move to ready-for-handover.");
            }

            $checklist = $this->checklist->for($locked);
            if (! $checklist->isComplete()) {
                throw new DomainException('Possession checklist is not complete: '.implode(' ', $checklist->reasons()));
            }

            $locked->forceFill(['status' => PossessionCaseStatus::ReadyForHandover])->save();

            PossessionHandover::firstOrCreate(
                ['possession_case_id' => $locked->id],
                [
                    'booking_id' => $locked->booking_id,
                    'status' => PossessionHandoverStatus::ReadyForHandover,
                    'created_by' => $actor->id,
                ],
            );

            $this->log($locked, PossessionActivityType::PossessionReadyForHandover, "Possession {$locked->case_number} is ready for handover.", $actor);
            Log::info('possession_case.ready_for_handover', ['possession_case_id' => $locked->id, 'by' => $actor->id]);

            return $locked->load('handover');
        });
    }

    public function putOnHold(PossessionCase $case, string $reason, User $actor): PossessionCase
    {
        if (trim($reason) === '') {
            throw new DomainException('A hold reason is required.');
        }

        return $this->transaction(function () use ($case, $reason, $actor): PossessionCase {
            $locked = $this->lock($case);

            if ($locked->status === PossessionCaseStatus::OnHold) {
                return $locked;
            }

            if (! $locked->status->isActive()) {
                throw new DomainException("A {$locked->status->label()} possession case cannot be put on hold.");
            }

            $locked->forceFill([
                'status' => PossessionCaseStatus::OnHold,
                'status_before_hold' => $locked->status->value,
                'hold_reason' => trim($reason),
            ])->save();

            $this->log($locked, PossessionActivityType::PossessionOnHold, "Possession {$locked->case_number} put on hold: ".trim($reason), $actor);

            return $locked;
        });
    }

    public function resume(PossessionCase $case, User $actor): PossessionCase
    {
        return $this->transaction(function () use ($case, $actor): PossessionCase {
            $locked = $this->lock($case);

            if ($locked->status !== PossessionCaseStatus::OnHold) {
                return $locked;
            }

            $back = PossessionCaseStatus::tryFrom((string) $locked->status_before_hold) ?? PossessionCaseStatus::EligibilityPending;
            $locked->forceFill([
                'status' => $back,
                'status_before_hold' => null,
                'hold_reason' => null,
            ])->save();

            $this->log($locked, PossessionActivityType::PossessionResumed, "Possession {$locked->case_number} resumed ({$back->label()}).", $actor);

            return $locked;
        });
    }

    public function cancel(PossessionCase $case, string $reason, User $actor): PossessionCase
    {
        return $this->transaction(function () use ($case, $reason, $actor): PossessionCase {
            $locked = $this->lock($case);

            if ($locked->status === PossessionCaseStatus::Cancelled) {
                return $locked;
            }

            if ($locked->status === PossessionCaseStatus::Completed) {
                throw new DomainException('A completed possession case cannot be cancelled.');
            }

            $locked->forceFill([
                'status' => PossessionCaseStatus::Cancelled,
                'cancellation_reason' => $reason ?: null,
            ])->save();

            $this->log($locked, PossessionActivityType::PossessionCancelled, "Possession {$locked->case_number} cancelled.", $actor);

            return $locked;
        });
    }

    private function lock(PossessionCase $case): PossessionCase
    {
        /** @var PossessionCase $locked */
        $locked = PossessionCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();
        $locked->load('booking.plot');

        return $locked;
    }

    private function log(PossessionCase $case, PossessionActivityType $type, string $description, User $actor): void
    {
        PossessionTimeline::record($type, $description, $case->booking, $case->booking?->plot, null, ['possession_case_id' => $case->id], $actor);
    }
}
