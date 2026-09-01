<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Plot;
use App\Models\User;

/**
 * SUPER ADMIN bypasses all of these via Gate::before (AppServiceProvider).
 */
class PlotPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PlotsView->value);
    }

    public function view(User $user, Plot $plot): bool
    {
        return $user->can(Permission::PlotsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::PlotsCreate->value);
    }

    public function bulkCreate(User $user): bool
    {
        return $user->can(Permission::PlotsBulkCreate->value);
    }

    public function update(User $user, Plot $plot): bool
    {
        return $user->can(Permission::PlotsUpdate->value);
    }

    /** Generic lifecycle status change (HOLD→BOOKED, BOOKED→SOLD/CANCELLED). */
    public function changeStatus(User $user, Plot $plot): bool
    {
        return $user->can(Permission::PlotsUpdate->value);
    }

    public function hold(User $user, Plot $plot): bool
    {
        return $user->can(Permission::PlotsHold->value);
    }

    public function release(User $user, Plot $plot): bool
    {
        return $user->can(Permission::PlotsRelease->value);
    }

    public function activate(User $user, Plot $plot): bool
    {
        return $user->can(Permission::PlotsActivate->value);
    }

    public function archive(User $user, Plot $plot): bool
    {
        return $user->can(Permission::PlotsArchive->value);
    }

    public function delete(User $user, Plot $plot): bool
    {
        return $user->can(Permission::PlotsDelete->value) && ! $plot->hasBusinessDependents();
    }
}
