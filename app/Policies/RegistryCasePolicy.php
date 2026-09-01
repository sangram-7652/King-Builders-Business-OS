<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\RegistryCaseStatus;
use App\Models\RegistryCase;
use App\Models\User;

class RegistryCasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::RegistryView->value);
    }

    public function view(User $user, RegistryCase $case): bool
    {
        return $user->can(Permission::RegistryView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::RegistryCreate->value);
    }

    public function update(User $user, RegistryCase $case): bool
    {
        return $user->can(Permission::RegistryUpdate->value) && $case->status->isActive();
    }

    public function schedule(User $user, RegistryCase $case): bool
    {
        return $user->can(Permission::RegistrySchedule->value) && $case->status->isActive();
    }

    public function complete(User $user, RegistryCase $case): bool
    {
        return $user->can(Permission::RegistryComplete->value)
            && in_array($case->status, [RegistryCaseStatus::Scheduled, RegistryCaseStatus::InProcess], true);
    }
}
