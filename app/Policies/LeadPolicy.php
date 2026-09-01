<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\LeadStatus;
use App\Enums\Permission;
use App\Models\Lead;
use App\Models\User;

/**
 * Access is scoped, not role-based. A user with `leads.view` sees only the
 * leads they own or are assigned to; `leads.view_all` lifts that. SUPER ADMIN
 * bypasses everything via Gate::before.
 */
class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::LeadsView->value);
    }

    public function view(User $user, Lead $lead): bool
    {
        return $user->can(Permission::LeadsView->value) && $lead->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::LeadsCreate->value);
    }

    public function update(User $user, Lead $lead): bool
    {
        return $user->can(Permission::LeadsUpdate->value)
            && $lead->isVisibleTo($user)
            && ! $lead->isConverted();
    }

    public function changeStatus(User $user, Lead $lead): bool
    {
        return $this->update($user, $lead);
    }

    public function assign(User $user, Lead $lead): bool
    {
        return $user->can(Permission::LeadsAssign->value) && $lead->isVisibleTo($user);
    }

    public function followUp(User $user, Lead $lead): bool
    {
        return $user->can(Permission::LeadsFollowUp->value)
            && $lead->isVisibleTo($user)
            && $lead->status !== LeadStatus::Converted;
    }

    public function convert(User $user, Lead $lead): bool
    {
        return $user->can(Permission::LeadsConvert->value)
            && $lead->isVisibleTo($user)
            && ($lead->status === LeadStatus::Qualified || $lead->status === LeadStatus::Converted);
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->can(Permission::LeadsDelete->value)
            && $lead->isVisibleTo($user)
            && ! $lead->isConverted();
    }
}
