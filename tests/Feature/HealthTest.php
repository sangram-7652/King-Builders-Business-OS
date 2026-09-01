<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes the framework health endpoint', function () {
    $this->get('/up')->assertOk();
});

it('redirects the root to the dashboard', function () {
    $this->get('/')->assertRedirect('/dashboard');
});

it('renders the dashboard with the admin shell', function () {
    $this->get('/dashboard')
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertSee('Business OS', false);
});

it('renders the UI kit gallery', function () {
    $this->get('/ui-kit')
        ->assertOk()
        ->assertSee('UI Kit');
});

it('can reach the configured database connection', function () {
    expect(DB::connection()->getPdo())->not->toBeNull();
});
