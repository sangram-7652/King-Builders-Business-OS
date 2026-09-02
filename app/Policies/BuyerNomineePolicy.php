<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BuyerNominee;
use App\Models\User;

class BuyerNomineePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('buyers.view');
    }

    public function view(User $user, BuyerNominee $nominee): bool
    {
        return $user->can('buyers.view');
    }

    public function create(User $user): bool
    {
        return $user->can('transfer.create');
    }
}
