<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Masters\MasterModel;
use App\Models\User;

/**
 * Single policy for every Master Data model (M2). Registered against all master
 * model classes in AppServiceProvider.
 *
 * SUPER ADMIN bypasses all of these via Gate::before.
 */
class MasterDataPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::MastersView->value);
    }

    public function view(User $user, MasterModel $model): bool
    {
        return $user->can(Permission::MastersView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::MastersCreate->value);
    }

    public function update(User $user, MasterModel $model): bool
    {
        return $user->can(Permission::MastersUpdate->value);
    }

    /** Activate / deactivate. */
    public function toggleStatus(User $user, MasterModel $model): bool
    {
        return $user->can(Permission::MastersUpdate->value);
    }

    /**
     * Hard delete. Blocked for system rows and rows referenced by business data
     * — the Action re-checks and throws, this keeps the button hidden too.
     */
    public function delete(User $user, MasterModel $model): bool
    {
        return $user->can(Permission::MastersDelete->value)
            && ! $model->isSystem()
            && ! $model->isReferenced();
    }
}
