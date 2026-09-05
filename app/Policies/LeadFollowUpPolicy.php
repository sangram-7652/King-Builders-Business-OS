<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\LeadFollowUp;
use App\Models\User;

/**
 * Follow-up queue access (M13.1). Scoped like leads: a user works their own
 * follow-ups (assigned or created, or on a lead they can see) unless they hold
 * `leads.view_all`. SUPER ADMIN bypasses via Gate::before.
 */
class LeadFollowUpPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::FollowUpsView->value);
    }

    public function view(User $user, LeadFollowUp $followUp): bool
    {
        return $user->can(Permission::FollowUpsView->value) && $this->visible($user, $followUp);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::FollowUpsCreate->value)
            || $user->can(Permission::LeadsFollowUp->value);
    }

    public function update(User $user, LeadFollowUp $followUp): bool
    {
        return $user->can(Permission::FollowUpsUpdate->value)
            && $this->visible($user, $followUp)
            && $followUp->isOpen();
    }

    public function complete(User $user, LeadFollowUp $followUp): bool
    {
        return $user->can(Permission::FollowUpsComplete->value) && $this->visible($user, $followUp);
    }

    /** complete/reschedule/cancel share the same gate. */
    public function reschedule(User $user, LeadFollowUp $followUp): bool
    {
        return $this->complete($user, $followUp);
    }

    public function cancel(User $user, LeadFollowUp $followUp): bool
    {
        return $this->complete($user, $followUp);
    }

    private function visible(User $user, LeadFollowUp $followUp): bool
    {
        return $user->can('leads.view_all')
            || $followUp->assigned_to === $user->id
            || $followUp->created_by === $user->id
            || $followUp->lead?->isVisibleTo($user);
    }
}
