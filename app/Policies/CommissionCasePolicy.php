<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CommissionCaseStatus;
use App\Enums\Permission;
use App\Models\CommissionCase;
use App\Models\User;

/**
 * SUPER ADMIN bypasses via Gate::before.
 *
 * `commission.generate` covers generating a booking's cases;
 * `commission.recalculate` covers re-snapshotting a still-open case. The
 * review / hold / payout / reversal abilities land in M14.5.
 */
class CommissionCasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::CommissionView->value);
    }

    public function view(User $user, CommissionCase $case): bool
    {
        return $user->can(Permission::CommissionView->value);
    }

    /** Generate the commission cases for a booking (class-level check with the booking passed by the component). */
    public function generate(User $user): bool
    {
        return $user->can(Permission::CommissionGenerate->value);
    }

    public function recalculate(User $user, CommissionCase $case): bool
    {
        return $user->can(Permission::CommissionRecalculate->value) && $case->status->isRecalculable();
    }

    public function approve(User $user, CommissionCase $case): bool
    {
        return $user->can(Permission::CommissionApprove->value)
            && $case->status->canTransitionTo(CommissionCaseStatus::Approved);
    }

    public function hold(User $user, CommissionCase $case): bool
    {
        return $user->can(Permission::CommissionApprove->value)
            && $case->status->canTransitionTo(CommissionCaseStatus::OnHold);
    }

    public function resume(User $user, CommissionCase $case): bool
    {
        return $user->can(Permission::CommissionApprove->value)
            && $case->status === CommissionCaseStatus::OnHold;
    }

    public function cancel(User $user, CommissionCase $case): bool
    {
        return $user->can(Permission::CommissionApprove->value)
            && $case->status->canTransitionTo(CommissionCaseStatus::Cancelled);
    }

    public function recordPayout(User $user, CommissionCase $case): bool
    {
        return $user->can(Permission::CommissionPayout->value) && $case->status->isPayable();
    }

    public function voidPayout(User $user, CommissionCase $case): bool
    {
        return $user->can(Permission::CommissionPayout->value)
            && $case->status !== CommissionCaseStatus::Reversed;
    }

    public function reverse(User $user, CommissionCase $case): bool
    {
        return $user->can(Permission::CommissionReverse->value)
            && $case->status->canTransitionTo(CommissionCaseStatus::Reversed);
    }
}
