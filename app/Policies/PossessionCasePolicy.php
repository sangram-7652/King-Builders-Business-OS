<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\PossessionCase;
use App\Models\User;

/**
 * SUPER ADMIN bypasses via Gate::before.
 *
 * Every ability checks the module permission AND that the user may view the
 * case's booking — the isolation guard that makes guessing a case id (IDOR)
 * useless.
 */
class PossessionCasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PossessionView->value);
    }

    public function view(User $user, PossessionCase $case): bool
    {
        return $user->can(Permission::PossessionView->value) && $this->canReach($user, $case);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::PossessionCreate->value);
    }

    public function update(User $user, PossessionCase $case): bool
    {
        return $user->can(Permission::PossessionCreate->value)
            && $case->status->isActive() && $this->canReach($user, $case);
    }

    public function schedule(User $user, PossessionCase $case): bool
    {
        return $user->can(Permission::PossessionSchedule->value)
            && $case->status->isActive() && $this->canReach($user, $case);
    }

    public function inspect(User $user, PossessionCase $case): bool
    {
        return $user->can(Permission::PossessionInspect->value)
            && $case->status->isActive() && $this->canReach($user, $case);
    }

    public function clear(User $user, PossessionCase $case): bool
    {
        return $user->can(Permission::PossessionClear->value)
            && $case->status->isActive() && $this->canReach($user, $case);
    }

    public function complete(User $user, PossessionCase $case): bool
    {
        return $user->can(Permission::PossessionComplete->value) && $this->canReach($user, $case);
    }

    private function canReach(User $user, PossessionCase $case): bool
    {
        $case->loadMissing('booking');

        return $case->booking !== null && $user->can('view', $case->booking);
    }
}
