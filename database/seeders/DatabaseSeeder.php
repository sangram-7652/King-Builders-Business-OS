<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\Masters\MasterDataSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
        $this->call(MasterDataSeeder::class);

        // The bootstrap Super Admin. In production its password MUST come from
        // SEED_SUPERADMIN_PASSWORD (min 12 chars) — the seeder refuses to create
        // the account with a known default. Locally / in tests a fixed
        // "password" keeps dev and the suite friction-free.
        $this->ensureUser(
            email: (string) env('SEED_SUPERADMIN_EMAIL', 'super@kingbuilders.test'),
            name: (string) env('SEED_SUPERADMIN_NAME', 'Super Admin'),
            mobile: (string) env('SEED_SUPERADMIN_MOBILE', '+910000000000'),
            role: RoleName::SuperAdmin,
            enforceStrongPassword: true,
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
     *
     * When $enforceStrongPassword is true and the account does not yet exist,
     * the password is taken from SEED_SUPERADMIN_PASSWORD and, in production, a
     * missing / weak value aborts the seed rather than shipping a known
     * credential.
     */
    private function ensureUser(
        string $email,
        string $name,
        string $mobile,
        RoleName $role,
        bool $enforceStrongPassword = false,
    ): void {
        $user = User::firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $password = $enforceStrongPassword
                ? $this->resolveBootstrapPassword()
                : 'password';

            $user->fill([
                'name' => $name,
                'mobile' => $mobile,
                'password' => Hash::make($password),
                'status' => UserStatus::Active,
            ]);
            $user->email_verified_at = now();
            $user->save();
        }

        $user->syncRoles([$role->value]);
    }

    /**
     * The Super Admin's initial password. Outside production a well-known
     * default is fine; in production SEED_SUPERADMIN_PASSWORD (>= 12 chars) is
     * mandatory, and its absence is a hard failure — never a silent fallback to
     * a shipped credential.
     */
    private function resolveBootstrapPassword(): string
    {
        $password = trim((string) env('SEED_SUPERADMIN_PASSWORD', ''));

        if ($password !== '') {
            if (mb_strlen($password) < 12) {
                throw new RuntimeException('SEED_SUPERADMIN_PASSWORD must be at least 12 characters.');
            }

            return $password;
        }

        if (app()->environment('production')) {
            throw new RuntimeException(
                'Refusing to seed the Super Admin in production without SEED_SUPERADMIN_PASSWORD '
                .'(min 12 chars). Set it in the environment before running `php artisan db:seed --force`, '
                .'then unset it once the account exists.'
            );
        }

        return 'password';
    }
}
