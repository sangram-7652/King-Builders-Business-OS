<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\PaymentPromise;
use App\Models\User;

class PaymentPromisePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PromisesView->value);
    }

    public function view(User $user, PaymentPromise $promise): bool
    {
        return $user->can(Permission::PromisesView->value)
            && ($user->can('collections.view_all') || $promise->collectionCase?->assigned_to === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::PromisesCreate->value);
    }

    public function update(User $user, PaymentPromise $promise): bool
    {
        return $user->can(Permission::PromisesUpdate->value)
            && ($user->can('collections.view_all') || $promise->collectionCase?->assigned_to === $user->id);
    }
}
