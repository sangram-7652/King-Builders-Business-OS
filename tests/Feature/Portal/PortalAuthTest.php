<?php

declare(strict_types=1);

use App\Actions\Customers\ActivateCustomerPortal;
use App\Actions\Customers\InviteCustomerToPortal;
use App\Enums\CustomerActivityType;
use App\Enums\CustomerPortalStatus;
use App\Exceptions\DomainException;
use App\Livewire\Portal\Auth\Activate;
use App\Livewire\Portal\Auth\Login;
use App\Models\Buyer;
use App\Models\CustomerInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('invites a buyer and moves them to invited', function () {
    ['buyer' => $buyer] = inviteBuyer();

    expect($buyer->portal_status)->toBe(CustomerPortalStatus::Invited)
        ->and($buyer->portal_invited_at)->not->toBeNull()
        ->and($buyer->portalActivities()->where('type', CustomerActivityType::Invited->value)->exists())->toBeTrue()
        ->and(CustomerInvitation::where('buyer_id', $buyer->id)->usable()->count())->toBe(1);
});

it('refuses to invite a buyer with no email', function () {
    $buyer = Buyer::factory()->create(['status' => 'active', 'email' => null]);

    expect(fn () => app(InviteCustomerToPortal::class)->handle($buyer, User::factory()->create()))
        ->toThrow(DomainException::class, 'email');
});

it('activates the portal from a valid token and the token is single-use', function () {
    ['token' => $token, 'buyer' => $buyer] = inviteBuyer();

    $activated = app(ActivateCustomerPortal::class)->handle($token, 'invite', 'Portal-pw-1234');

    expect($activated->portal_status)->toBe(CustomerPortalStatus::Active)
        ->and($activated->password)->not->toBeNull()
        ->and(Hash::check('Portal-pw-1234', $activated->password))->toBeTrue()
        ->and($activated->canAccessPortal())->toBeTrue();

    expect(fn () => app(ActivateCustomerPortal::class)->handle($token, 'invite', 'x'))
        ->toThrow(DomainException::class);
});

it('rejects an expired token', function () {
    ['token' => $token] = inviteBuyer();
    CustomerInvitation::query()->update(['expires_at' => Carbon::now()->subHour()]);

    expect(fn () => app(ActivateCustomerPortal::class)->handle($token, 'invite', 'Portal-pw-1234'))
        ->toThrow(DomainException::class, 'expired');
});

it('supersedes an earlier invite when re-invited', function () {
    ['buyer' => $buyer] = inviteBuyer();
    $first = CustomerInvitation::where('buyer_id', $buyer->id)->latest('id')->first();

    app(InviteCustomerToPortal::class)->handle($buyer->fresh(), User::factory()->create());

    expect($first->fresh()->used_at)->not->toBeNull()
        ->and(CustomerInvitation::where('buyer_id', $buyer->id)->usable()->count())->toBe(1);
});

it('signs an active customer in via the portal login screen', function () {
    $buyer = activePortalBuyer();

    Livewire::test(Login::class)
        ->set('email', $buyer->email)
        ->set('password', 'Portal-pw-1234')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('portal.dashboard'));

    expect(auth('customer')->id())->toBe($buyer->id)
        ->and($buyer->fresh()->portalActivities()->where('type', CustomerActivityType::Login->value)->exists())->toBeTrue();
});

it('rejects login for an invited-but-not-activated customer', function () {
    ['buyer' => $buyer] = inviteBuyer();
    $buyer->forceFill(['password' => Hash::make('whatever')])->save(); // even with a password, status gates it

    Livewire::test(Login::class)
        ->set('email', $buyer->email)
        ->set('password', 'whatever')
        ->call('login')
        ->assertHasErrors('email');

    expect(auth('customer')->check())->toBeFalse();
});

it('rejects login for a suspended customer', function () {
    $buyer = activePortalBuyer();
    $buyer->forceFill(['portal_status' => CustomerPortalStatus::Suspended])->save();

    Livewire::test(Login::class)
        ->set('email', $buyer->email)->set('password', 'Portal-pw-1234')
        ->call('login')->assertHasErrors('email');
});

it('throttles repeated failed portal logins', function () {
    $buyer = activePortalBuyer();

    for ($i = 0; $i < 5; $i++) {
        Livewire::test(Login::class)->set('email', $buyer->email)->set('password', 'wrong')->call('login')->assertHasErrors('email');
    }

    Livewire::test(Login::class)
        ->set('email', $buyer->email)->set('password', 'Portal-pw-1234')
        ->call('login')
        ->assertHasErrors('email');
});

it('shows an invalid-link message for a bad activation token', function () {
    Livewire::test(Activate::class, ['token' => str_repeat('z', 64)])
        ->assertSet('tokenValid', false)
        ->assertSee('no longer valid');
});
