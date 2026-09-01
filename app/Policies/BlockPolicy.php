<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Block;
use App\Models\User;

/**
 * Blocks use the Project permission set — there is no separate `blocks.*`
 * permission. Managing blocks is part of managing a project.
 *
 * SUPER ADMIN bypasses all of these via Gate::before.
 */
class BlockPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ProjectsView->value);
    }

    public function view(User $user, Block $block): bool
    {
        return $user->can(Permission::ProjectsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ProjectsUpdate->value);
    }

    public function update(User $user, Block $block): bool
    {
        return $user->can(Permission::ProjectsUpdate->value);
    }

    public function toggleStatus(User $user, Block $block): bool
    {
        return $user->can(Permission::ProjectsUpdate->value);
    }

    public function delete(User $user, Block $block): bool
    {
        return $user->can(Permission::ProjectsUpdate->value) && ! $block->hasBusinessDependents();
    }
}
