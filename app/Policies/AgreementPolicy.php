<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AgreementStatus;
use App\Enums\Permission;
use App\Models\Agreement;
use App\Models\User;

class AgreementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::AgreementsView->value);
    }

    public function view(User $user, Agreement $agreement): bool
    {
        return $user->can(Permission::AgreementsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::AgreementsCreate->value);
    }

    /** Prepare / send / sign — everything short of approval. */
    public function update(User $user, Agreement $agreement): bool
    {
        return $user->can(Permission::AgreementsUpdate->value)
            && ! in_array($agreement->status, [AgreementStatus::Approved, AgreementStatus::Cancelled], true);
    }

    public function approve(User $user, Agreement $agreement): bool
    {
        return $user->can(Permission::AgreementsApprove->value) && $agreement->status === AgreementStatus::Signed;
    }

    public function cancel(User $user, Agreement $agreement): bool
    {
        return $user->can(Permission::AgreementsUpdate->value)
            && $agreement->canTransitionTo(AgreementStatus::Cancelled);
    }
}
