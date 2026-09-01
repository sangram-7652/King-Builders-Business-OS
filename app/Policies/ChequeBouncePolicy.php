<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ChequeBounce;
use App\Models\User;

class ChequeBouncePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ChequesView->value);
    }

    public function view(User $user, ChequeBounce $bounce): bool
    {
        return $user->can(Permission::ChequesView->value);
    }

    /** Clear a pending cheque. */
    public function update(User $user): bool
    {
        return $user->can(Permission::ChequesUpdate->value);
    }

    /** Record a bounce (and drive the M7 consequence). */
    public function bounce(User $user): bool
    {
        return $user->can(Permission::ChequesBounce->value);
    }
}
