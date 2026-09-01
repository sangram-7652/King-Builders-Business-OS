<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\UserStatus;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class CreateUser
{
    use RunsInTransaction;

    /**
     * @param  list<string>  $roleNames
     */
    public function handle(
        string $name,
        string $email,
        ?string $mobile,
        string $password,
        UserStatus $status,
        array $roleNames,
    ): User {
        return $this->transaction(function () use ($name, $email, $mobile, $password, $status, $roleNames): User {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'mobile' => $mobile,
                'password' => Hash::make($password),
                'status' => $status,
            ]);

            // Not mass-assignable; the Business OS provisions accounts directly
            // so there is no self-service email verification step.
            $user->forceFill(['email_verified_at' => now()])->save();

            $user->syncRoles($roleNames);

            Log::info('user.created', [
                'user_id' => $user->id,
                'roles' => $roleNames,
                'by' => auth()->id(),
            ]);

            return $user;
        });
    }
}
