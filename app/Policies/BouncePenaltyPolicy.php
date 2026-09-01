<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PenaltyStatus;
use App\Enums\Permission;
use App\Models\BouncePenalty;
use App\Models\User;

class BouncePenaltyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PenaltiesView->value);
    }

    public function view(User $user, BouncePenalty $penalty): bool
    {
        return $user->can(Permission::PenaltiesView->value);
    }

    public function assess(User $user): bool
    {
        return $user->can(Permission::PenaltiesAssess->value);
    }

    public function approve(User $user, BouncePenalty $penalty): bool
    {
        return $user->can(Permission::PenaltiesApprove->value) && $penalty->status === PenaltyStatus::Assessed;
    }

    public function cancel(User $user, BouncePenalty $penalty): bool
    {
        return ($user->can(Permission::PenaltiesApprove->value) || $user->can(Permission::PenaltiesAssess->value))
            && $penalty->status !== PenaltyStatus::Cancelled;
    }
}
