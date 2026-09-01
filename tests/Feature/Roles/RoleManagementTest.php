<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Enums\RoleName;
use App\Livewire\Roles\RoleForm;
use App\Livewire\Roles\RoleIndex;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('creates a custom role with a chosen permission set', function () {
    $admin = makeUser([RoleName::Admin->value]);

    Livewire::actingAs($admin)
        ->test(RoleForm::class)
        ->set('name', 'Front Desk')
        ->set('permissions', [Permission::BuyersView->value, Permission::BookingsView->value])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('roles.index'));

    $role = Role::where('name', 'Front Desk')->firstOrFail();
    expect($role->hasPermissionTo(Permission::BuyersView->value))->toBeTrue()
        ->and($role->hasPermissionTo(Permission::BuyersCreate->value))->toBeFalse();
});

it('updates a role permission set and it propagates to its users', function () {
    $admin = makeUser([RoleName::Admin->value]);
    $role = Role::create(['name' => 'Ops', 'guard_name' => 'web']);
    $member = makeUser();
    $member->assignRole($role);

    expect($member->fresh()->can(Permission::RegistryUpdate->value))->toBeFalse();

    Livewire::actingAs($admin)
        ->test(RoleForm::class, ['role' => $role])
        ->set('permissions', [Permission::RegistryView->value, Permission::RegistryUpdate->value])
        ->call('save')
        ->assertHasNoErrors();

    expect($member->fresh()->can(Permission::RegistryUpdate->value))->toBeTrue();
});

it('blocks toggling all permissions in a group helper', function () {
    $admin = makeUser([RoleName::Admin->value]);

    $component = Livewire::actingAs($admin)
        ->test(RoleForm::class)
        ->set('name', 'Finance Team')
        ->call('toggleGroup', 'finance', true);

    expect($component->get('permissions'))
        ->toContain(Permission::PaymentsApprove->value)
        ->toContain(Permission::PaymentsView->value);

    $component->call('toggleGroup', 'finance', false);
    expect($component->get('permissions'))->toBe([]);
});

it('refuses to delete a system role', function () {
    $admin = makeUser([RoleName::Admin->value]);
    $viewer = Role::where('name', RoleName::Viewer->value)->firstOrFail();

    Livewire::actingAs($admin)
        ->test(RoleIndex::class)
        ->call('delete', $viewer->id);

    expect(Role::where('name', RoleName::Viewer->value)->exists())->toBeTrue();
});

it('refuses to delete a role that still has users', function () {
    $admin = makeUser([RoleName::Admin->value]);
    $role = Role::create(['name' => 'Temp', 'guard_name' => 'web']);
    makeUser()->assignRole($role);

    Livewire::actingAs($admin)
        ->test(RoleIndex::class)
        ->call('delete', $role->id);

    expect(Role::where('name', 'Temp')->exists())->toBeTrue();
});

it('deletes an empty custom role', function () {
    $admin = makeUser([RoleName::Admin->value]);
    $role = Role::create(['name' => 'Disposable', 'guard_name' => 'web']);

    Livewire::actingAs($admin)
        ->test(RoleIndex::class)
        ->call('delete', $role->id);

    expect(Role::where('name', 'Disposable')->exists())->toBeFalse();
});

it('does not let the Super Admin role be edited', function () {
    $admin = makeUser([RoleName::Admin->value]);
    $superRole = Role::where('name', RoleName::SuperAdmin->value)->firstOrFail();

    Livewire::actingAs($admin)
        ->test(RoleForm::class, ['role' => $superRole])
        ->assertForbidden();
});

it('requires roles.view to see the roles screen', function () {
    $noAccess = makeUser();
    $this->actingAs($noAccess)->get('/roles')->assertForbidden();

    $withAccess = makeUser(permissions: [Permission::RolesView->value]);
    $this->actingAs($withAccess)->get('/roles')->assertOk();
});
