<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

/**
 * Run DatabaseSeeder::run() directly (no `db:seed` command → no production
 * confirmation prompt), the same way tests/Pest.php's seedRbac() invokes a
 * seeder. Returns nothing; throws whatever the seeder throws.
 */
function runDatabaseSeeder(Application $app): void
{
    $app->make(DatabaseSeeder::class)->setContainer($app)->run();
}

/** Set (or clear, with null) an env var the way Laravel's env() will read it. */
function seedEnv(string $key, ?string $value): void
{
    if ($value === null) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);

        return;
    }

    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

afterEach(function () {
    seedEnv('SEED_SUPERADMIN_PASSWORD', null);
    seedEnv('SEED_SUPERADMIN_EMAIL', null);
    seedEnv('SEED_SUPERADMIN_NAME', null);
    app()->detectEnvironment(fn () => 'testing');
});

it('refuses to seed the Super Admin in production without SEED_SUPERADMIN_PASSWORD', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => runDatabaseSeeder($this->app))
        ->toThrow(RuntimeException::class, 'SEED_SUPERADMIN_PASSWORD');

    expect(User::where('email', 'super@kingbuilders.test')->exists())->toBeFalse();
});

it('rejects a SEED_SUPERADMIN_PASSWORD shorter than 12 characters', function () {
    app()->detectEnvironment(fn () => 'production');
    seedEnv('SEED_SUPERADMIN_PASSWORD', 'short');

    expect(fn () => runDatabaseSeeder($this->app))
        ->toThrow(RuntimeException::class, 'at least 12 characters');
});

it('seeds the Super Admin in production with a supplied strong password (never a fixed default)', function () {
    app()->detectEnvironment(fn () => 'production');
    seedEnv('SEED_SUPERADMIN_PASSWORD', 'correct-horse-battery-staple');
    seedEnv('SEED_SUPERADMIN_EMAIL', 'admin@erp.example.com');

    runDatabaseSeeder($this->app);

    $admin = User::where('email', 'admin@erp.example.com')->firstOrFail();

    expect($admin->isSuperAdmin())->toBeTrue()
        ->and(Hash::check('correct-horse-battery-staple', $admin->password))->toBeTrue()
        ->and(Hash::check('password', $admin->password))->toBeFalse();
});

it('still allows the well-known "password" default outside production', function () {
    runDatabaseSeeder($this->app); // env() is 'testing' here

    $admin = User::where('email', 'super@kingbuilders.test')->firstOrFail();

    expect(Hash::check('password', $admin->password))->toBeTrue();
});

it('never rewrites an existing Super Admin password on re-seed in production', function () {
    $existing = User::factory()->create([
        'email' => 'super@kingbuilders.test',
        'password' => Hash::make('the-real-operator-password'),
    ]);

    app()->detectEnvironment(fn () => 'production'); // no SEED_SUPERADMIN_PASSWORD set
    runDatabaseSeeder($this->app);              // must NOT throw — account already exists

    expect(Hash::check('the-real-operator-password', $existing->fresh()->password))->toBeTrue();
});
