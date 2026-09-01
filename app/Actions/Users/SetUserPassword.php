<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class SetUserPassword
{
    public function handle(User $user, string $password, bool $logoutOtherSessions = true): User
    {
        $user->forceFill([
            'password' => Hash::make($password),
            'remember_token' => null,
        ])->save();

        // Password is never logged — only the fact that it changed.
        Log::info('user.password_set', [
            'user_id' => $user->id,
            'by' => auth()->id(),
        ]);

        return $user;
    }
}
