<?php

declare(strict_types=1);

use App\Livewire\Buyers\BuyerForm;
use App\Livewire\Buyers\BuyerIndex;
use App\Models\Booking;
use App\Models\BookingBuyer;
use App\Models\Buyer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('redirects a guest and forbids a user without buyers.view', function () {
    $this->get('/buyers')->assertRedirect('/login');
    $this->actingAs(makeUser())->get('/buyers')->assertForbidden();
});

it('allows buyers.view to list but not create', function () {
    $viewer = makeUser(permissions: ['buyers.view']);
    $this->actingAs($viewer)->get('/buyers')->assertOk();
    $this->actingAs($viewer)->get('/buyers/create')->assertForbidden();
});

it('blocks the create component for a view-only user', function () {
    Livewire::actingAs(makeUser(permissions: ['buyers.view']))
        ->test(BuyerForm::class)
        ->assertForbidden();
});

it('hides the KYC section from a user without buyers.documents', function () {
    $buyer = Buyer::factory()->create();

    Livewire::actingAs(makeUser(permissions: ['buyers.view', 'buyers.update']))
        ->test(BuyerForm::class, ['buyer' => $buyer])
        ->assertDontSee('KYC identifiers');
});

it('blocks deleting a buyer that has a booking', function () {
    $manager = buyerManager();
    $buyer = Buyer::factory()->create();
    $booking = Booking::factory()->create();
    BookingBuyer::factory()->create(['booking_id' => $booking->id, 'buyer_id' => $buyer->id, 'is_primary' => true, 'ownership_percentage' => 100]);

    Livewire::actingAs($manager)
        ->test(BuyerIndex::class)
        ->call('delete', $buyer->id)
        ->assertForbidden();

    expect(Buyer::find($buyer->id))->not->toBeNull();
});

it('lets a Super Admin reach every buyer screen', function () {
    $buyer = Buyer::factory()->create();
    $this->actingAs(superAdmin());

    $this->get(route('buyers.index'))->assertOk();
    $this->get(route('buyers.create'))->assertOk();
    $this->get(route('buyers.show', $buyer))->assertOk();
    $this->get(route('buyers.edit', $buyer))->assertOk();
});
