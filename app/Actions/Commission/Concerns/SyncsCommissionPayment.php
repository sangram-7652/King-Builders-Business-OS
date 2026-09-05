<?php

declare(strict_types=1);

namespace App\Actions\Commission\Concerns;

use App\Enums\CommissionCaseEventType;
use App\Enums\CommissionCaseStatus;
use App\Models\CommissionCase;
use App\Models\User;

/**
 * Recomputes a case's `paid_amount` from its non-voided payouts and moves the
 * status between APPROVED / PARTIALLY_PAID / PAID accordingly (M14.5). Never
 * touches a CANCELLED / REVERSED / ON_HOLD case's status.
 */
trait SyncsCommissionPayment
{
    protected function syncPaidAmount(CommissionCase $case, User $actor): void
    {
        $paid = (string) $case->recordedPayouts()->sum('amount');
        $paid = bcadd($paid, '0', 2);
        $total = bcadd((string) $case->commission_amount, '0', 2);

        $case->forceFill(['paid_amount' => $paid])->save();

        if (! in_array($case->status, [
            CommissionCaseStatus::Approved,
            CommissionCaseStatus::PartiallyPaid,
            CommissionCaseStatus::Paid,
        ], true)) {
            return;
        }

        $target = match (true) {
            bccomp($paid, '0', 2) <= 0 => CommissionCaseStatus::Approved,
            bccomp($paid, $total, 2) >= 0 => CommissionCaseStatus::Paid,
            default => CommissionCaseStatus::PartiallyPaid,
        };

        if ($target === $case->status) {
            return;
        }

        $case->forceFill(['status' => $target])->save();

        if ($target === CommissionCaseStatus::Paid) {
            $case->recordEvent(CommissionCaseEventType::FullyPaid, "Fully paid — ₹{$paid}.", [], $actor);
        }
    }
}
