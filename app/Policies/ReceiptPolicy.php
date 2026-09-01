<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Receipt;
use App\Models\User;

class ReceiptPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ReceiptsView->value);
    }

    public function view(User $user, Receipt $receipt): bool
    {
        return $user->can(Permission::ReceiptsView->value);
    }

    /** Download / (re)issue the printable PDF. */
    public function generate(User $user, Receipt $receipt): bool
    {
        return $user->can(Permission::ReceiptsGenerate->value) || $user->can(Permission::ReceiptsView->value);
    }
}
