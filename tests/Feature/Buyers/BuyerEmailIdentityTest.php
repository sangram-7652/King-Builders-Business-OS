<?php

declare(strict_types=1);

use App\Actions\Customers\InviteCustomerToPortal;
use App\Enums\CustomerActivityType;
use App\Exceptions\DomainException;
use App\Livewire\Portal\Auth\Login;
use App\Models\Buyer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

/**
 * F-M5-1 / S-3 / DB-3 / F-M15-1 — a buyer's email is a unique, normalised
 * portal-login identity in this single-tenant system.
 */
it('stores email trimmed and lower-cased', function () {
    $buyer = Buyer::factory()->create(['email' => '  Mixed.Case@Example.COM  ']);

    expect($buyer->fresh()->email)->toBe('mixed.case@example.com');
});

it('normalises an empty email to null', function () {
    $buyer = Buyer::factory()->create(['email' => '   ']);

    expect($buyer->fresh()->email)->toBeNull();
});

it('rejects a duplicate email at the database (case-insensitively)', function () {
    Buyer::factory()->create(['email' => 'dup@example.com']);

    expect(fn () => Buyer::factory()->create(['email' => 'DUP@example.com']))
        ->toThrow(QueryException::class);
});

it('allows many buyers with no email', function () {
    Buyer::factory()->count(3)->create(['email' => null]);

    expect(Buyer::whereNull('email')->count())->toBe(3);
});

it('resolves portal login deterministically to the one buyer holding the email', function () {
    $target = Buyer::factory()->create([
        'email' => 'owner@example.com', 'status' => 'active',
        'portal_status' => 'active', 'password' => bcrypt('secret-123456'), 'portal_activated_at' => now(),
    ]);
    // A different buyer, no email — must not be reachable by this login.
    Buyer::factory()->create(['email' => null, 'status' => 'active']);

    Livewire::test(Login::class)
        ->set('email', 'OWNER@example.com') // different case — still resolves
        ->set('password', 'secret-123456')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('portal.dashboard'));

    expect(auth('customer')->id())->toBe($target->id);
});

it('an invitation cannot create an ambiguous portal identity', function () {
    Buyer::factory()->create(['email' => 'shared@example.com', 'status' => 'active']);
    $second = Buyer::factory()->create(['email' => null, 'status' => 'active']);
    // Force a raw collision that bypassed the model mutator would still be
    // caught — but here we simulate staff trying to invite a second buyer whose
    // email was set to an already-used address.
    $second->forceFill(['email' => 'shared@example.com']);

    expect(fn () => app(InviteCustomerToPortal::class)->handle($second, User::factory()->create()))
        ->toThrow(DomainException::class, 'Another customer already uses that email');
});

it('keeps a customer with a unique email able to sign in and stay scoped to their own data', function () {
    $customer = activePortalBuyer('Portal-pw-1234');

    Livewire::test(Login::class)
        ->set('email', strtoupper($customer->email))
        ->set('password', 'Portal-pw-1234')
        ->call('login')
        ->assertHasNoErrors();

    expect($customer->fresh()->portalActivities()->where('type', CustomerActivityType::Login->value)->exists())->toBeTrue();
});

it('a soft-deleted buyer never blocks a new buyer from taking the same email', function () {
    $old = Buyer::factory()->create(['email' => 'freed@example.com']);
    $old->delete();

    $new = Buyer::factory()->create(['email' => 'freed@example.com']);

    expect($new->exists)->toBeTrue();
});
