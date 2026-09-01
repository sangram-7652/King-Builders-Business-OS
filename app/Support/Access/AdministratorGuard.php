<?php

declare(strict_types=1);

namespace App\Support\Access;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\User;

/**
 * Guards against an administrator locking everyone (including themselves) out
 * of the system. "Administrative access" here means an ACTIVE user holding the
 * SUPER ADMIN role.
 */
final class AdministratorGuard
{
    /**
     * Number of active Super Admins, optionally excluding one user id.
     */
    public static function activeSuperAdminCount(?int $excludingUserId = null): int
    {
        return User::query()
            ->where('status', UserStatus::Active->value)
            ->when($excludingUserId, fn ($q) => $q->whereKeyNot($excludingUserId))
            ->whereHas('roles', fn ($q) => $q->where('name', RoleName::SuperAdmin->value))
            ->count();
    }

    /**
     * Would applying a change to $user leave the system with no active Super Admin?
     */
    public static function isLastActiveSuperAdmin(User $user): bool
    {
        if (! $user->hasRole(RoleName::SuperAdmin->value) || ! $user->isActive()) {
            return false;
        }

        return self::activeSuperAdminCount(excludingUserId: $user->getKey()) === 0;
    }
}
