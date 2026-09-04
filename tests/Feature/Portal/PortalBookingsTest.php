<?php

declare(strict_types=1);

use App\Livewire\Portal\Bookings\Index;
use App\Livewire\Portal\Bookings\Show;
use App\Models\Booking;
use App\Models\BookingBuyer;
use App\Models\Buyer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

// portalBooking() lives in tests/Pest.php so it is available to every test
// process (it is shared with PortalPaymentsTest and must not depend on this
// file being loaded first — e.g. under --parallel).

it('lists only the customer’s own bookings', function () {
    ['customer' => $customer, 'booking' => $booking] = portalBooking();
    portalBooking(); // someone else's booking

    Livewire::actingAs($customer, 'customer')
        ->test(Index::class)
        ->assertOk()
        ->assertSee($booking->booking_number)
        ->assertViewHas('bookings', fn ($p) => $p->total() === 1);
});

it('shows a booking the customer owns with M8-derived figures', function () {
    ['customer' => $customer, 'booking' => $booking] = portalBooking();

    Livewire::actingAs($customer, 'customer')
        ->test(Show::class, ['booking' => $booking])
        ->assertOk()
        ->assertSee($booking->booking_number)
        ->assertSee('Payment schedule')
        ->assertViewHas('financials', fn ($f) => $f->paid->store() === '300000.00' && $f->outstanding->store() === '700000.00')
        ->assertViewHas('schedule', fn ($s) => count($s['rows']) === 4);
});

it('404s when a customer requests a booking they do not own (IDOR)', function () {
    ['customer' => $customer] = portalBooking();
    ['booking' => $other] = portalBooking();

    Livewire::actingAs($customer, 'customer')
        ->test(Show::class, ['booking' => $other])
        ->assertStatus(404);
});

it('404s the booking show for an unknown id', function () {
    ['customer' => $customer] = portalBooking();
    $ghost = tap(new Booking, fn (Booking $b) => $b->id = 999999);

    Livewire::actingAs($customer, 'customer')
        ->test(Show::class, ['booking' => $ghost])
        ->assertStatus(404);
});

it('lets a co-buyer (non-primary) see the shared booking', function () {
    ['booking' => $booking] = portalBooking();
    $coBuyer = Buyer::factory()->withPortalAccess()->create(['status' => 'active']);
    BookingBuyer::factory()->create(['booking_id' => $booking->id, 'buyer_id' => $coBuyer->id, 'ownership_percentage' => 0, 'is_primary' => false]);

    Livewire::actingAs($coBuyer->fresh(), 'customer')
        ->test(Show::class, ['booking' => $booking])
        ->assertOk()
        ->assertSee($booking->booking_number);
});
