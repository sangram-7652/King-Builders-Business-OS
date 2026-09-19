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
 * Approves a commission case (M14.5). PENDING_REVIEW / ON_HOLD → APPROVED.
 * Row-locked and idempotent. After approval the calculation snapshot is frozen
 * (M14.4 already blocks recalculation) and payouts can be recorded.
 */
class ApproveCommissionCase
{
    use RunsInTransaction;

    public function handle(CommissionCase $case, User $actor): CommissionCase
    {
        return $this->transaction(function () use ($case, $actor): CommissionCase {
            /** @var CommissionCase $locked */
            $locked = CommissionCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === CommissionCaseStatus::Approved) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo(CommissionCaseStatus::Approved)) {
                throw new DomainException("A {$locked->status->label()} commission case cannot be approved.");
            }

            if (! $locked->is_eligible || $locked->current_calculation_id === null) {
                throw new DomainException('This case has no eligible calculation to approve.');
            }

            $locked->loadMissing('partner');

            $locked->forceFill([
                'status' => CommissionCaseStatus::Approved,
                'approved_at' => now(),
                'approved_by' => $actor->id,
                'held_at' => null,
                'hold_reason' => null,
            ])->save();

            $locked->recordEvent(
                CommissionCaseEventType::Approved,
                "Approved — ₹{$locked->payable_amount} payable to {$locked->partner?->displayName()} (gross ₹{$locked->commission_amount}, advance-adjusted ₹{$locked->advance_adjusted_amount}).",
                [],
                $actor,
            );

            Log::info('commission.case_approved', ['case_id' => $locked->id, 'amount' => $locked->commission_amount, 'by' => $actor->id]);

            return $locked;
        });
    }
}
