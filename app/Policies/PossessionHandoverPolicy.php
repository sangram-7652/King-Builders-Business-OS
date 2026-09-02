<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\PossessionHandover;
use App\Models\User;

class PossessionHandoverPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PossessionView->value);
    }

    public function view(User $user, PossessionHandover $handover): bool
    {
        return $user->can(Permission::PossessionView->value) && $this->canReach($user, $handover);
    }

    public function complete(User $user, PossessionHandover $handover): bool
    {
        return $user->can(Permission::PossessionComplete->value) && $this->canReach($user, $handover);
    }

    private function canReach(User $user, PossessionHandover $handover): bool
    {
        $handover->loadMissing('booking');

        return $handover->booking !== null && $user->can('view', $handover->booking);
    }
}
