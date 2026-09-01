<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\CollectionCase;
use App\Models\User;

/**
 * Scoped access (M8), mirroring the M5 lead pattern: `collections.view` sees
 * only cases assigned to you; `collections.view_all` lifts that. SUPER ADMIN
 * bypasses via Gate::before. Every domain action re-checks these rules — the
 * UI is never the only guard, and no collection user can touch M7 money.
 */
class CollectionCasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::CollectionsView->value);
    }

    public function view(User $user, CollectionCase $case): bool
    {
        return $user->can(Permission::CollectionsView->value) && $case->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CollectionsCreate->value);
    }

    public function update(User $user, CollectionCase $case): bool
    {
        return $user->can(Permission::CollectionsUpdate->value) && $case->isVisibleTo($user);
    }

    public function assign(User $user, CollectionCase $case): bool
    {
        return $user->can(Permission::CollectionsAssign->value);
    }

    public function followUp(User $user, CollectionCase $case): bool
    {
        return $user->can(Permission::CollectionsFollowUp->value) && $case->isVisibleTo($user);
    }

    public function viewReports(User $user): bool
    {
        return $user->can(Permission::CollectionsReports->value);
    }
}
