<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Enums\CommissionCaseEventType;
use App\Enums\CommissionCaseStatus;
use App\Exceptions\DomainException;
use App\Models\CommissionCase;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Cancels an un-approved commission case (M14.5) — PENDING_REVIEW / ON_HOLD →
 * CANCELLED. An approved / paid case must be REVERSED instead (that carries
 * clawback), so it is rejected here.
 */
class CancelCommissionCase
{
    use RunsInTransaction;

    public function handle(CommissionCase $case, User $actor, string $reason): CommissionCase
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A cancellation needs a reason.');
        }

        return $this->transaction(function () use ($case, $actor, $reason): CommissionCase {
            /** @var CommissionCase $locked */
            $locked = CommissionCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === CommissionCaseStatus::Cancelled) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo(CommissionCaseStatus::Cancelled)) {
                throw new DomainException("A {$locked->status->label()} commission case cannot be cancelled — reverse it instead.");
            }

            $locked->forceFill([
                'status' => CommissionCaseStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            $locked->recordEvent(CommissionCaseEventType::Cancelled, "Cancelled — {$reason}", [], $actor);

            Log::info('commission.case_cancelled', ['case_id' => $locked->id, 'by' => $actor->id]);

            return $locked;
        });
    }
}
