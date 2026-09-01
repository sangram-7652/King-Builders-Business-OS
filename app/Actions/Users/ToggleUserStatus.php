<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use App\Models\User;
use App\Support\Access\AdministratorGuard;
use Illuminate\Support\Facades\Log;

class ToggleUserStatus
{
    public function handle(User $user, ?User $actor = null): User
    {
        $actor ??= auth()->user();

        if ($actor !== null && $actor->is($user)) {
            throw new DomainException('You cannot change your own account status.');
        }

        if ($user->isActive() && AdministratorGuard::isLastActiveSuperAdmin($user)) {
            throw new DomainException('You cannot deactivate the last active Super Admin.');
        }

        $user->status = $user->isActive() ? UserStatus::Inactive : UserStatus::Active;
        $user->save();

        Log::info('user.status_changed', [
            'user_id' => $user->id,
            'status' => $user->status->value,
            'by' => $actor?->id,
        ]);

        return $user;
    }
}
