<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Livewire\Auth\Login;
use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('renders every authenticated screen for a Super Admin (full HTTP + Livewire stack)', function () {
    $admin = makeUser([RoleName::SuperAdmin->value]);
    $target = makeUser([RoleName::Viewer->value], attributes: ['name' => 'Screen Target']);
    $role = Role::where('name', RoleName::Accountant->value)->firstOrFail();

    $this->actingAs($admin);

    $this->get('/dashboard')->assertOk()->assertSee('Dashboard');
    $this->get('/users')->assertOk()->assertSee('Screen Target');
    $this->get('/users/create')->assertOk()->assertSee('New user');
    $this->get("/users/{$target->id}")->assertOk()->assertSee($target->email);
    $this->get("/users/{$target->id}/edit")->assertOk()->assertSee('Edit user');
    $this->get('/roles')->assertOk()->assertSee(RoleName::Accountant->value);
    $this->get('/roles/create')->assertOk()->assertSee('New role');
    $this->get("/roles/{$role->id}")->assertOk()->assertSee('Permissions');
    $this->get("/roles/{$role->id}/edit")->assertOk()->assertSee('Edit role');
});

it('hides nav items a user has no permission for', function () {
    $viewerOnly = makeUser(permissions: ['reports.view']); // no users.view / roles.view

    $response = $this->actingAs($viewerOnly)->get('/dashboard')->assertOk();
    $response->assertDontSee(route('users.index'));
    $response->assertDontSee(route('roles.index'));
});

it('shows nav items a user does have permission for', function () {
    $manager = makeUser(permissions: ['users.view']);

    $this->actingAs($manager)->get('/dashboard')
        ->assertOk()
        ->assertSee(route('users.index'));
});

it('lets the seeded super admin sign in end to end', function () {
    $this->seed(DatabaseSeeder::class);

    Livewire\Livewire::test(Login::class)
        ->set('email', 'super@kingbuilders.test')
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));

    expect(auth()->user()->isSuperAdmin())->toBeTrue();
});
