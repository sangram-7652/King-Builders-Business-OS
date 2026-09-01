<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes the framework health endpoint', function () {
    $this->get('/up')->assertOk();
});

it('redirects the root to the dashboard', function () {
    $this->get('/')->assertRedirect('/dashboard');
});

it('sends guests from the dashboard to the login screen', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('renders the dashboard with the admin shell for an authenticated user', function () {
    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertSee('Business OS', false);
});

it('can reach the configured database connection', function () {
    expect(DB::connection()->getPdo())->not->toBeNull();
});
