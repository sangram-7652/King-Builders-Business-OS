<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;

/**
 * SUPER ADMIN bypasses all of these via Gate::before (AppServiceProvider).
 */
class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ProjectsView->value);
    }

    public function view(User $user, Project $project): bool
    {
        return $user->can(Permission::ProjectsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ProjectsCreate->value);
    }

    public function update(User $user, Project $project): bool
    {
        return $user->can(Permission::ProjectsUpdate->value);
    }

    /** Lifecycle status transitions. */
    public function changeStatus(User $user, Project $project): bool
    {
        return $user->can(Permission::ProjectsUpdate->value);
    }

    /** Un-archive (is_active → true). */
    public function activate(User $user, Project $project): bool
    {
        return $user->can(Permission::ProjectsActivate->value);
    }

    /** Archive (is_active → false). */
    public function archive(User $user, Project $project): bool
    {
        return $user->can(Permission::ProjectsArchive->value);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->can(Permission::ProjectsDelete->value) && ! $project->hasBusinessDependents();
    }
}
