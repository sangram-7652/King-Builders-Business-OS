<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\Masters\MasterDataSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
        $this->call(MasterDataSeeder::class);

        $this->ensureUser(
            email: 'super@kingbuilders.test',
            name: 'Super Admin',
            mobile: '+910000000000',
            role: RoleName::SuperAdmin,
        );

        if (app()->environment('local', 'testing')) {
            $this->ensureUser(
                email: 'viewer@kingbuilders.test',
                name: 'Vera Viewer',
                mobile: '+910000000001',
                role: RoleName::Viewer,
            );
        }
    }

    /**
     * Create the account on first run only; never overwrite an existing
     * password / profile on re-seed. Always re-assert the role.
     */
    private function ensureUser(string $email, string $name, string $mobile, RoleName $role): void
    {
        $user = User::firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $user->fill([
                'name' => $name,
                'mobile' => $mobile,
                'password' => Hash::make('password'),
                'status' => UserStatus::Active,
            ]);
            $user->email_verified_at = now();
            $user->save();
        }

        $user->syncRoles([$role->value]);
    }
}
