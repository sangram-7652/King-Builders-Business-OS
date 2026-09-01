<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PaymentPlanStatus;
use App\Enums\Permission;
use App\Models\PaymentPlan;
use App\Models\User;

/**
 * SUPER ADMIN bypasses these via Gate::before. Every domain action re-checks
 * the same rules — the UI is never the only guard.
 */
class PaymentPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PaymentPlansView->value);
    }

    public function view(User $user, PaymentPlan $plan): bool
    {
        return $user->can(Permission::PaymentPlansView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::PaymentPlansCreate->value);
    }

    public function update(User $user, PaymentPlan $plan): bool
    {
        return $user->can(Permission::PaymentPlansUpdate->value) && $plan->isDraft();
    }

    public function activate(User $user, PaymentPlan $plan): bool
    {
        return $user->can(Permission::PaymentPlansActivate->value)
            && $plan->canTransitionTo(PaymentPlanStatus::Active);
    }

    public function cancel(User $user, PaymentPlan $plan): bool
    {
        return $user->can(Permission::PaymentPlansUpdate->value)
            && $plan->canTransitionTo(PaymentPlanStatus::Cancelled);
    }

    /** Waive / un-waive an installment. */
    public function waiveInstallment(User $user, PaymentPlan $plan): bool
    {
        return $user->can(Permission::PaymentPlansUpdate->value) && $plan->isActive();
    }
}
