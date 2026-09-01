<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Support\Access\AdministratorGuard;

/**
 * SUPER ADMIN bypasses all of these via Gate::before (AppServiceProvider).
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::UsersView->value);
    }

    public function view(User $user, User $model): bool
    {
        return $user->can(Permission::UsersView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::UsersCreate->value);
    }

    public function update(User $user, User $model): bool
    {
        return $user->can(Permission::UsersUpdate->value);
    }

    public function delete(User $user, User $model): bool
    {
        if (! $user->can(Permission::UsersDelete->value)) {
            return false;
        }

        // Never delete yourself, and never delete the last active Super Admin.
        if ($user->is($model)) {
            return false;
        }

        return ! AdministratorGuard::isLastActiveSuperAdmin($model);
    }

    /**
     * Deactivate / reactivate. Cannot deactivate yourself or the last Super Admin.
     */
    public function toggleStatus(User $user, User $model): bool
    {
        if (! $user->can(Permission::UsersUpdate->value)) {
            return false;
        }

        if ($user->is($model)) {
            return false;
        }

        return ! ($model->isActive() && AdministratorGuard::isLastActiveSuperAdmin($model));
    }

    public function setPassword(User $user, User $model): bool
    {
        return $user->can(Permission::UsersUpdate->value);
    }

    /**
     * Assign/remove roles. Cannot strip the Super Admin role from the last one.
     */
    public function assignRoles(User $user, User $model): bool
    {
        return $user->can(Permission::UsersUpdate->value);
    }
}
