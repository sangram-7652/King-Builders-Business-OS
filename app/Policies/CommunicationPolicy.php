<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CommunicationStatus;
use App\Enums\Permission;
use App\Models\Communication;
use App\Models\User;

/**
 * SUPER ADMIN bypasses via Gate::before.
 *
 * Single-tenant install: staff with `communications.view` see the whole log;
 * `communications.manage` covers retry / cancel (and templates + rules in later
 * M16 phases). A customer's own communication history is exposed separately
 * through the M15 portal, scoped to their buyer id.
 */
class CommunicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::CommunicationsView->value);
    }

    public function view(User $user, Communication $communication): bool
    {
        return $user->can(Permission::CommunicationsView->value);
    }

    public function retry(User $user, Communication $communication): bool
    {
        return $user->can(Permission::CommunicationsManage->value)
            && $communication->status === CommunicationStatus::Failed;
    }

    public function cancel(User $user, Communication $communication): bool
    {
        return $user->can(Permission::CommunicationsManage->value)
            && in_array($communication->status, [CommunicationStatus::Pending, CommunicationStatus::Queued], true);
    }
}
