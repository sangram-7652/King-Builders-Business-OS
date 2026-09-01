<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\BookingBuyer;
use App\Models\Buyer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the M6 tables with the expected columns', function () {
    expect(Schema::hasColumns('bookings', [
        'booking_number', 'project_id', 'block_id', 'plot_id', 'active_plot_id',
        'booking_date', 'status', 'base_area', 'base_rate', 'base_amount',
        'plc_amount', 'charge_amount', 'subtotal', 'discount_amount', 'tax_amount',
        'final_amount', 'pricing_snapshot', 'created_by', 'confirmed_at', 'confirmed_by',
        'cancelled_at', 'cancelled_by', 'cancellation_reason',
        'price_overridden', 'price_override_by', 'price_override_at', 'price_override_reason',
        'deleted_at',
    ]))->toBeTrue();

    expect(Schema::hasColumns('booking_buyers', ['booking_id', 'buyer_id', 'ownership_percentage', 'is_primary']))->toBeTrue();
    expect(Schema::hasColumns('booking_price_lines', [
        'booking_id', 'type', 'name', 'calculation_type', 'quantity', 'rate', 'amount',
        'plc_type_id', 'charge_type_id', 'tax_rate_id', 'sort_order', 'metadata',
    ]))->toBeTrue();

    // buyer_id is NOT on bookings
    expect(Schema::hasColumn('bookings', 'buyer_id'))->toBeFalse();
});

it('enforces the unique booking number', function () {
    Booking::factory()->create(['booking_number' => 'BK-000042']);

    expect(fn () => Booking::factory()->create(['booking_number' => 'BK-000042']))
        ->toThrow(QueryException::class);
});

it('enforces one buyer per booking in the pivot', function () {
    $booking = Booking::factory()->create();
    $buyer = Buyer::factory()->create();
    BookingBuyer::factory()->create(['booking_id' => $booking->id, 'buyer_id' => $buyer->id]);

    expect(fn () => BookingBuyer::factory()->create(['booking_id' => $booking->id, 'buyer_id' => $buyer->id]))
        ->toThrow(QueryException::class);
});

it('cascades price lines and buyers when a booking is force-deleted', function () {
    $booking = Booking::factory()->create();
    BookingBuyer::factory()->create(['booking_id' => $booking->id]);
    $booking->priceLines()->create([
        'type' => 'base', 'name' => 'Base', 'calculation_type' => 'per_sqft',
        'quantity' => 100, 'rate' => 2000, 'amount' => 200000,
    ]);

    $booking->forceDelete();

    expect(BookingBuyer::where('booking_id', $booking->id)->count())->toBe(0)
        ->and(DB::table('booking_price_lines')->where('booking_id', $booking->id)->count())->toBe(0);
});

it('restricts hard-deleting a plot that has bookings', function () {
    $booking = Booking::factory()->create();

    expect(fn () => $booking->plot->forceDelete())->toThrow(QueryException::class);
});
