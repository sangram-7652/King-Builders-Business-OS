<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Users\UserForm;
use App\Livewire\Users\UserIndex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('never stores a password in plaintext', function () {
    $admin = makeUser([RoleName::Admin->value]);

    Livewire::actingAs($admin)
        ->test(UserForm::class)
        ->set('name', 'Nadia New')
        ->set('email', 'nadia@example.com')
        ->set('mobile', '+919812345678')
        ->set('status', UserStatus::Active->value)
        ->set('roles', [RoleName::SalesExecutive->value])
        ->set('password', 'a-secret-password')
        ->set('password_confirmation', 'a-secret-password')
        ->call('save')
        ->assertHasNoErrors();

    $user = User::where('email', 'nadia@example.com')->firstOrFail();

    expect($user->password)->not->toBe('a-secret-password')
        ->and(Hash::check('a-secret-password', $user->password))->toBeTrue()
        ->and($user->hasRole(RoleName::SalesExecutive->value))->toBeTrue();
});

it('lets an authorised admin edit a user and change roles', function () {
    $admin = makeUser([RoleName::Admin->value]);
    $target = makeUser([RoleName::Viewer->value]);

    Livewire::actingAs($admin)
        ->test(UserForm::class, ['user' => $target])
        ->set('name', 'Renamed Person')
        ->set('roles', [RoleName::Accountant->value])
        ->call('save')
        ->assertHasNoErrors();

    $target->refresh();
    expect($target->name)->toBe('Renamed Person')
        ->and($target->hasRole(RoleName::Accountant->value))->toBeTrue()
        ->and($target->hasRole(RoleName::Viewer->value))->toBeFalse();
});

it('prevents deactivating your own account', function () {
    $admin = makeUser([RoleName::Admin->value]);

    Livewire::actingAs($admin)
        ->test(UserIndex::class)
        ->call('toggleStatus', $admin->id)
        ->assertForbidden();

    expect($admin->fresh()->isActive())->toBeTrue();
});

it('prevents deleting your own account', function () {
    $admin = makeUser([RoleName::Admin->value]);

    Livewire::actingAs($admin)
        ->test(UserIndex::class)
        ->call('delete', $admin->id)
        ->assertForbidden();

    expect(User::find($admin->id))->not->toBeNull();
});

it('prevents removing the last active Super Admin role', function () {
    $onlySuper = makeUser([RoleName::SuperAdmin->value]);
    $editor = makeUser([RoleName::Admin->value]);

    Livewire::actingAs($editor)
        ->test(UserForm::class, ['user' => $onlySuper])
        ->set('roles', [RoleName::Admin->value]) // drop Super Admin
        ->call('save')
        ->assertHasErrors('roles');

    expect($onlySuper->fresh()->hasRole(RoleName::SuperAdmin->value))->toBeTrue();
});

it('prevents deactivating the last active Super Admin', function () {
    $onlySuper = makeUser([RoleName::SuperAdmin->value]);
    $editor = makeUser([RoleName::Admin->value]);

    Livewire::actingAs($editor)
        ->test(UserIndex::class)
        ->call('toggleStatus', $onlySuper->id);

    expect($onlySuper->fresh()->isActive())->toBeTrue();
});

it('allows removing a Super Admin when another active one exists', function () {
    $superA = makeUser([RoleName::SuperAdmin->value]);
    $superB = makeUser([RoleName::SuperAdmin->value]);
    $editor = makeUser([RoleName::Admin->value]);

    Livewire::actingAs($editor)
        ->test(UserForm::class, ['user' => $superA])
        ->set('roles', [RoleName::Admin->value])
        ->call('save')
        ->assertHasNoErrors();

    expect($superA->fresh()->hasRole(RoleName::SuperAdmin->value))->toBeFalse()
        ->and($superB->fresh()->hasRole(RoleName::SuperAdmin->value))->toBeTrue();
});

it('filters the user list by role and status', function () {
    $admin = makeUser([RoleName::Admin->value]);
    makeUser([RoleName::Accountant->value], attributes: ['name' => 'Anita Accountant']);
    makeUser([RoleName::Viewer->value], attributes: ['name' => 'Victor Viewer']);

    Livewire::actingAs($admin)
        ->test(UserIndex::class)
        ->set('role', RoleName::Accountant->value)
        ->assertSee('Anita Accountant')
        ->assertDontSee('Victor Viewer');
});
