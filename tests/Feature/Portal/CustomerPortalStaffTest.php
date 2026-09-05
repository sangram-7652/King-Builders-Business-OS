<?php

declare(strict_types=1);

use App\Enums\CustomerPortalStatus;
use App\Livewire\Buyers\BuyerShow;
use App\Models\Buyer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

function portalManager(): User
{
    return makeUser(permissions: ['buyers.view', 'buyers.portal']);
}

it('lets a staff user with buyers.portal invite a customer and see the one-time link', function () {
    $buyer = Buyer::factory()->create(['status' => 'active', 'email' => 'inv@example.com']);

    Livewire::actingAs(portalManager())
        ->test(BuyerShow::class, ['buyer' => $buyer])
        ->call('invitePortal')
        ->assertHasNoErrors()
        ->assertSet('portalLink', fn ($v) => is_string($v) && str_contains($v, '/portal/activate/'));

    expect($buyer->fresh()->portal_status)->toBe(CustomerPortalStatus::Invited);
});

it('stops a staff user without buyers.portal from managing portal access', function () {
    $buyer = Buyer::factory()->create(['status' => 'active', 'email' => 'x@example.com']);

    Livewire::actingAs(makeUser(permissions: ['buyers.view']))
        ->test(BuyerShow::class, ['buyer' => $buyer])
        ->call('invitePortal')
        ->assertForbidden();
});

it('suspends and restores portal access from Customer 360', function () {
    $buyer = Buyer::factory()->create(['status' => 'active', 'email' => 's@example.com', 'portal_status' => 'active', 'password' => bcrypt('x')]);
    $manager = portalManager();

    Livewire::actingAs($manager)->test(BuyerShow::class, ['buyer' => $buyer])->call('suspendPortal');
    expect($buyer->fresh()->portal_status)->toBe(CustomerPortalStatus::Suspended);

    Livewire::actingAs($manager)->test(BuyerShow::class, ['buyer' => $buyer->fresh()])->call('restorePortal');
    expect($buyer->fresh()->portal_status)->toBe(CustomerPortalStatus::Active);
});

it('issues a reset link for an already-active customer', function () {
    $buyer = Buyer::factory()->create(['status' => 'active', 'email' => 'r@example.com', 'portal_status' => 'active', 'password' => bcrypt('x')]);

    Livewire::actingAs(portalManager())
        ->test(BuyerShow::class, ['buyer' => $buyer])
        ->call('resetPortalPassword')
        ->assertSet('portalLink', fn ($v) => str_contains((string) $v, '/portal/reset-password/'));
});

it('adds buyers.portal to the Sales Manager role', function () {
    seedRbac();
    expect(Role::findByName('Sales Manager', 'web')->hasPermissionTo('buyers.portal'))->toBeTrue();
});
