<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Exceptions\DomainException;
use App\Models\User;
use App\Support\Access\AdministratorGuard;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

class DeleteUser
{
    use RunsInTransaction;

    public function handle(User $user, ?User $actor = null): void
    {
        $actor ??= auth()->user();

        if ($actor !== null && $actor->is($user)) {
            throw new DomainException('You cannot delete your own account.');
        }

        if (AdministratorGuard::isLastActiveSuperAdmin($user)) {
            throw new DomainException('You cannot delete the last active Super Admin.');
        }

        $this->transaction(function () use ($user, $actor): void {
            $id = $user->id;
            $user->roles()->detach();
            $user->delete();

            Log::info('user.deleted', ['user_id' => $id, 'by' => $actor?->id]);
        });
    }
}
