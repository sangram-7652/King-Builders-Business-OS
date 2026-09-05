<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Buyer;
use App\Models\User;

/**
 * SUPER ADMIN bypasses all of these via Gate::before.
 */
class BuyerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::BuyersView->value);
    }

    public function view(User $user, Buyer $buyer): bool
    {
        return $user->can(Permission::BuyersView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::BuyersCreate->value);
    }

    public function update(User $user, Buyer $buyer): bool
    {
        return $user->can(Permission::BuyersUpdate->value);
    }

    public function changeStatus(User $user, Buyer $buyer): bool
    {
        return $user->can(Permission::BuyersArchive->value);
    }

    /** Reveal the full PAN / Aadhaar. Masked values need only `buyers.view`. */
    public function viewDocuments(User $user, Buyer $buyer): bool
    {
        return $user->can(Permission::BuyersDocuments->value);
    }

    /** Invite / reset / suspend the customer's self-service portal access (M15). */
    public function managePortal(User $user, Buyer $buyer): bool
    {
        return $user->can(Permission::BuyersPortal->value);
    }

    public function delete(User $user, Buyer $buyer): bool
    {
        return $user->can(Permission::BuyersDelete->value) && ! $buyer->hasBusinessDependents();
    }
}
