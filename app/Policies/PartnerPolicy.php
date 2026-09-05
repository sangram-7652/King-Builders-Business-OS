<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Partner;
use App\Models\User;

/**
 * SUPER ADMIN bypasses via Gate::before.
 *
 * Single-tenant install: "tenant isolation" here means route-model-binding 404
 * on a bad id + these permission checks. A partner is not user-owned, so there
 * is no per-user visibility scoping — `partners.view` sees every partner.
 */
class PartnerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::PartnersView->value);
    }

    public function view(User $user, Partner $partner): bool
    {
        return $user->can(Permission::PartnersView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::PartnersCreate->value);
    }

    public function update(User $user, Partner $partner): bool
    {
        return $user->can(Permission::PartnersUpdate->value);
    }

    /** Move a partner along its lifecycle (hold / suspend / retire / …). */
    public function changeStatus(User $user, Partner $partner): bool
    {
        return $user->can(Permission::PartnersUpdate->value);
    }

    /** Approve a DRAFT / PENDING partner into ACTIVE. */
    public function approve(User $user, Partner $partner): bool
    {
        return $user->can(Permission::PartnersApprove->value);
    }

    /** Grant / revoke project authorisation. */
    public function authorizeProjects(User $user, Partner $partner): bool
    {
        return $user->can(Permission::PartnersAuthorize->value);
    }

    public function delete(User $user, Partner $partner): bool
    {
        return $user->can(Permission::PartnersCreate->value) && ! $partner->hasBusinessDependents();
    }

    /** Reveal the full PAN / bank account number. Masked values need only `partners.view`. */
    public function viewSensitive(User $user, Partner $partner): bool
    {
        return $user->can(Permission::PartnersApprove->value);
    }
}
