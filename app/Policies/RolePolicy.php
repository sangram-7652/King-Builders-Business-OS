<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;

/**
 * SUPER ADMIN bypasses all of these via Gate::before (AppServiceProvider).
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::RolesView->value);
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can(Permission::RolesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::RolesCreate->value);
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can(Permission::RolesUpdate->value);
    }

    /**
     * The Super Admin role's permission set is immutable (it is all-powerful by
     * definition and enforced via Gate::before regardless).
     */
    public function updatePermissions(User $user, Role $role): bool
    {
        return $user->can(Permission::RolesUpdate->value) && ! $role->isSuperAdmin();
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can(Permission::RolesDelete->value)
            && ! $role->isSystem()
            && $role->users()->count() === 0;
    }
}
