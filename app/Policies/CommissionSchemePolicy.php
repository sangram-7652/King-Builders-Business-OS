<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CommissionSchemeStatus;
use App\Enums\Permission;
use App\Models\CommissionScheme;
use App\Models\User;

/**
 * SUPER ADMIN bypasses via Gate::before.
 *
 * `commission_schemes.manage` covers create + edit-draft + rules;
 * `commission_schemes.publish` covers publish / archive / new-version. A
 * published or archived version can never be edited by anyone (immutability is
 * also enforced in the actions).
 */
class CommissionSchemePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::CommissionSchemesView->value);
    }

    public function view(User $user, CommissionScheme $scheme): bool
    {
        return $user->can(Permission::CommissionSchemesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::CommissionSchemesManage->value);
    }

    public function update(User $user, CommissionScheme $scheme): bool
    {
        return $user->can(Permission::CommissionSchemesManage->value) && $scheme->isEditable();
    }

    /** Add / change rules and slabs. */
    public function manageRules(User $user, CommissionScheme $scheme): bool
    {
        return $user->can(Permission::CommissionSchemesManage->value) && $scheme->isEditable();
    }

    public function publish(User $user, CommissionScheme $scheme): bool
    {
        return $user->can(Permission::CommissionSchemesPublish->value) && $scheme->isDraft();
    }

    public function archive(User $user, CommissionScheme $scheme): bool
    {
        return $user->can(Permission::CommissionSchemesPublish->value)
            && $scheme->status->canTransitionTo(CommissionSchemeStatus::Archived);
    }

    public function createVersion(User $user, CommissionScheme $scheme): bool
    {
        return $user->can(Permission::CommissionSchemesPublish->value);
    }

    /** Assign a scheme to a partner — a partner-management right, not a scheme one. */
    public function assignToPartner(User $user): bool
    {
        return $user->can(Permission::PartnersUpdate->value);
    }
}
