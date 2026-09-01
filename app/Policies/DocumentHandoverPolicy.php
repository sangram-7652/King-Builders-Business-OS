<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\HandoverStatus;
use App\Enums\Permission;
use App\Models\DocumentHandover;
use App\Models\User;

class DocumentHandoverPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::HandoverView->value);
    }

    public function view(User $user, DocumentHandover $handover): bool
    {
        return $user->can(Permission::HandoverView->value);
    }

    /** Advance through DOCUMENTS_READY / HANDOVER_SCHEDULED. */
    public function update(User $user, DocumentHandover $handover): bool
    {
        return $user->can(Permission::HandoverCreate->value) && $handover->status !== HandoverStatus::HandedOver;
    }

    public function complete(User $user, DocumentHandover $handover): bool
    {
        return $user->can(Permission::HandoverComplete->value);
    }
}
