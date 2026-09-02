<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PlotOwnershipHistory;
use App\Models\User;

class PlotOwnershipHistoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ownership.view');
    }

    public function view(User $user, PlotOwnershipHistory $period): bool
    {
        $period->loadMissing('booking');

        return $user->can('ownership.view')
            && $period->booking !== null && $user->can('view', $period->booking);
    }
}
