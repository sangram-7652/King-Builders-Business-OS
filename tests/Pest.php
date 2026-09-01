<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/*
| ---------------------------------------------------------------------------
| Test Case bindings
| ---------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)->in('Unit');

pest()->extend(TestCase::class)
    ->beforeEach(fn () => $this->withoutVite())
    ->in('Feature');

/*
| ---------------------------------------------------------------------------
| RBAC helpers
| ---------------------------------------------------------------------------
*/

function seedRbac(): void
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
}

/**
 * Create a user, optionally with roles and/or explicit permissions.
 *
 * @param  array<int, string>  $roles
 * @param  array<int, string>  $permissions
 */
function makeUser(array $roles = [], array $permissions = [], array $attributes = []): User
{
    $user = User::factory()->create($attributes);

    if ($roles !== []) {
        $user->syncRoles($roles);
    }

    if ($permissions !== []) {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);
    }

    return $user->fresh();
}

function superAdmin(): User
{
    seedRbac();

    return makeUser([RoleName::SuperAdmin->value]);
}

/**
 * A user holding the full masters.* permission set (no other access).
 */
function masterAdmin(): User
{
    return makeUser(permissions: [
        'masters.view', 'masters.create', 'masters.update', 'masters.delete',
    ]);
}

/**
 * A user holding the full projects.* permission set (no other access).
 */
function projectManager(): User
{
    return makeUser(permissions: [
        'projects.view', 'projects.create', 'projects.update',
        'projects.delete', 'projects.activate', 'projects.archive',
    ]);
}

/**
 * A user holding the full plots.* permission set (no other access).
 */
function plotManager(): User
{
    return makeUser(permissions: [
        'plots.view', 'plots.create', 'plots.update', 'plots.delete',
        'plots.hold', 'plots.release', 'plots.activate', 'plots.archive',
        'plots.bulk_create',
    ]);
}
