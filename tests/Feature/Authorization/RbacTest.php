<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\RoleName;
use App\Livewire\Users\UserIndex;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('redirects a guest away from a protected route', function () {
    $this->get('/users')->assertRedirect('/login');
});

it('forbids an authenticated user without the required permission', function () {
    $user = makeUser(); // no roles, no permissions

    $this->actingAs($user)->get('/users')->assertForbidden();
});

it('allows an authenticated user who has the required permission', function () {
    $user = makeUser(permissions: [Permission::UsersView->value]);

    $this->actingAs($user)->get('/users')->assertOk();
});

it('rejects a direct action call when the permission is missing', function () {
    $actor = makeUser(permissions: [Permission::UsersView->value]); // can view, cannot delete
    $target = makeUser();

    $this->actingAs($actor);

    expect($actor->can('delete', $target))->toBeFalse();

    Livewire\Livewire::test(UserIndex::class)
        ->call('delete', $target->id)
        ->assertForbidden();

    expect(User::find($target->id))->not->toBeNull();
});

it('grants a Super Admin every permission via Gate::before', function () {
    $user = makeUser([RoleName::SuperAdmin->value]);

    expect($user->can(Permission::PaymentsVerify->value))->toBeTrue()
        ->and($user->can('anything.at.all'))->toBeTrue()
        ->and($user->can(Permission::UsersDelete->value))->toBeTrue();
});

it('does not grant arbitrary permissions to a non-super-admin', function () {
    $user = makeUser([RoleName::Viewer->value]);

    expect($user->can(Permission::UsersDelete->value))->toBeFalse()
        ->and($user->can(Permission::BuyersView->value))->toBeTrue(); // viewer has *.view
});

it('applies permissions through an assigned role', function () {
    $role = Role::create(['name' => 'Booking Desk', 'guard_name' => 'web']);
    $role->syncPermissions([Permission::BookingsView->value, Permission::BookingsCreate->value]);

    $user = makeUser();
    expect($user->can(Permission::BookingsCreate->value))->toBeFalse();

    $user->assignRole($role);

    expect($user->fresh()->can(Permission::BookingsCreate->value))->toBeTrue()
        ->and($user->fresh()->can(Permission::BookingsCancel->value))->toBeFalse();
});

it('assigns and removes roles on a user', function () {
    $user = makeUser();

    $user->assignRole(RoleName::Accountant->value);
    expect($user->fresh()->hasRole(RoleName::Accountant->value))->toBeTrue()
        ->and($user->fresh()->can(Permission::PaymentsVerify->value))->toBeTrue();

    $user->removeRole(RoleName::Accountant->value);
    expect($user->fresh()->hasRole(RoleName::Accountant->value))->toBeFalse()
        ->and($user->fresh()->can(Permission::PaymentsVerify->value))->toBeFalse();
});

it('seeds all nine system roles and every permission', function () {
    expect(Role::count())->toBe(count(RoleName::cases()))
        ->and(Spatie\Permission\Models\Permission::count())->toBe(count(Permission::cases()));

    foreach (RoleName::names() as $name) {
        expect(Role::where('name', $name)->exists())->toBeTrue();
    }
});
