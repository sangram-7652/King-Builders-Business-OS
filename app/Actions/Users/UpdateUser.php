<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use App\Models\User;
use App\Support\Access\AdministratorGuard;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

class UpdateUser
{
    use RunsInTransaction;

    /**
     * @param  list<string>  $roleNames
     */
    public function handle(
        User $user,
        string $name,
        string $email,
        ?string $mobile,
        UserStatus $status,
        array $roleNames,
    ): User {
        return $this->transaction(function () use ($user, $name, $email, $mobile, $status, $roleNames): User {
            $this->assertKeepsAnActiveSuperAdmin($user, $status, $roleNames);

            $user->fill([
                'name' => $name,
                'email' => $email,
                'mobile' => $mobile,
                'status' => $status,
            ])->save();

            $user->syncRoles($roleNames);

            Log::info('user.updated', [
                'user_id' => $user->id,
                'roles' => $roleNames,
                'status' => $status->value,
                'by' => auth()->id(),
            ]);

            return $user->refresh();
        });
    }

    /**
     * @param  list<string>  $roleNames
     */
    private function assertKeepsAnActiveSuperAdmin(User $user, UserStatus $status, array $roleNames): void
    {
        if (! AdministratorGuard::isLastActiveSuperAdmin($user)) {
            return;
        }

        $stillActiveSuperAdmin = $status === UserStatus::Active
            && in_array(RoleName::SuperAdmin->value, $roleNames, true);

        if (! $stillActiveSuperAdmin) {
            throw new DomainException(
                'This is the last active Super Admin. Assign the Super Admin role to another active user first.'
            );
        }
    }
}
