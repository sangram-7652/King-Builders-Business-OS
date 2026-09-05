<?php

declare(strict_types=1);

use App\Actions\Customers\UpdateCustomerProfile;
use App\Enums\CustomerPortalStatus;
use App\Enums\RoleName;
use App\Livewire\Portal\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('redirects a portal guest to the portal login (not the staff login)', function () {
    $this->get('/portal')->assertRedirect(route('portal.login'));
    $this->get('/portal/profile')->assertRedirect(route('portal.login'));
});

it('keeps a staff user out of the portal', function () {
    $staff = makeUser([RoleName::SuperAdmin->value]);

    // Staff auth is on the `web` guard; the portal is `auth:customer`.
    $this->actingAs($staff)->get('/portal')->assertRedirect(route('portal.login'));
    $this->actingAs($staff)->get('/portal/profile')->assertRedirect(route('portal.login'));
});

it('keeps a customer out of the staff area', function () {
    $customer = activePortalBuyer();

    $this->actingAs($customer, 'customer')->get('/dashboard')->assertRedirect(route('login'));
    $this->actingAs($customer, 'customer')->get('/buyers')->assertRedirect(route('login'));
    $this->actingAs($customer, 'customer')->get('/commissions')->assertRedirect(route('login'));
});

it('lets an active customer reach the portal dashboard and profile', function () {
    $customer = activePortalBuyer();

    $this->actingAs($customer, 'customer')->get('/portal')->assertOk()->assertSee($customer->first_name);
    $this->actingAs($customer, 'customer')->get('/portal/profile')->assertOk()->assertSee($customer->customer_code);
});

it('signs a suspended customer out at the next request', function () {
    $customer = activePortalBuyer();
    $this->actingAs($customer, 'customer');
    $customer->forceFill(['portal_status' => CustomerPortalStatus::Suspended])->save();

    $this->get('/portal')->assertRedirect(route('portal.login'));
    expect(auth('customer')->check())->toBeFalse();
});

it('a customer can only edit their own profile — the guard is the only identity source', function () {
    $a = activePortalBuyer();
    $b = activePortalBuyer();

    Livewire::actingAs($a, 'customer')
        ->test(Profile::class)
        ->set('phone', '9998887770')
        ->call('save')
        ->assertHasNoErrors();

    expect($a->fresh()->phone)->toBe('9998887770')
        ->and($b->fresh()->phone)->not->toBe('9998887770');
});

it('a customer cannot change a protected field even if it is smuggled into the update payload', function () {
    $customer = activePortalBuyer();
    $originalCode = $customer->customer_code;

    // Whatever reaches the action, only the whitelisted contact fields are written.
    app(UpdateCustomerProfile::class)->handle($customer, [
        'phone' => '9111111111',
        'customer_code' => 'HACKED',
        'status' => 'archived',
        'portal_status' => 'suspended',
        'pan_number' => 'ZZZZZ9999Z',
        'created_by' => 999,
    ]);

    $fresh = $customer->fresh();
    expect($fresh->phone)->toBe('9111111111')
        ->and($fresh->customer_code)->toBe($originalCode)
        ->and($fresh->status->value)->toBe('active')
        ->and($fresh->portal_status->value)->toBe('active');
});

it('never exposes the password hash or KYC numbers in the buyer array', function () {
    $customer = activePortalBuyer();
    $customer->forceFill(['pan_number' => 'ABCDE1234F'])->save();

    $array = $customer->fresh()->toArray();

    expect($array)->not->toHaveKey('password')
        ->and($array)->not->toHaveKey('remember_token')
        ->and($array)->not->toHaveKey('pan_number');
});
