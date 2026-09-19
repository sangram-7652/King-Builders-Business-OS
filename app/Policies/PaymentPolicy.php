<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Payment;
use App\Models\User;

/**
 * SUPER ADMIN bypasses these via Gate::before. A normal user can never delete
 * a confirmed financial record, and can never directly mutate a SUCCESS
 * payment's state — verification / reversal are separate, gated abilities.
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PaymentsView->value);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->can(Permission::PaymentsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::PaymentsCreate->value);
    }

    public function verify(User $user, Payment $payment): bool
    {
        return $user->can(Permission::PaymentsVerify->value) && $payment->isPending();
    }

    public function reverse(User $user, Payment $payment): bool
    {
        return $user->can(Permission::PaymentsReverse->value) && $payment->isSuccessful();
    }

    /** Payments are financial records — never deleted through the app. */
    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }
}
