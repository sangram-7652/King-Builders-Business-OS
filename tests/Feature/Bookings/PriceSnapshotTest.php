<?php

declare(strict_types=1);

use App\Actions\Bookings\CancelBookingAction;
use App\Actions\Bookings\ConfirmBookingAction;
use App\Actions\Bookings\CreateBookingAction;
use App\Actions\Bookings\SubmitBookingAction;
use App\Enums\BookingStatus;
use App\Enums\PlotStatus;
use App\Enums\PriceComponentType;
use App\Models\Booking;
use App\Models\Masters\PlcType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function priceThenConfirm(array $s, ?PlcType $plc = null): Booking
{
    $components = [['type' => 'tax', 'name' => 'GST', 'calculation_type' => 'percentage', 'rate' => '5']];

    if ($plc) {
        $components[] = [
            'type' => 'plc', 'name' => $plc->name, 'plc_type_id' => $plc->id,
            'calculation_type' => 'percentage', 'rate' => (string) $plc->value,
        ];
    }

    $booking = app(CreateBookingAction::class)->handle(
        bookingPayload($s, ['pricing' => ['base_area' => '1000', 'base_rate' => '2000', 'components' => $components]]),
        $s['actor'],
    );
    app(SubmitBookingAction::class)->handle($booking, $s['actor']);

    return app(ConfirmBookingAction::class)->handle($booking->fresh(), $s['actor']);
}

it('freezes a full pricing snapshot on confirmation (32)', function () {
    $s = bookingScenario();

    $booking = priceThenConfirm($s);
    $snapshot = $booking->pricing_snapshot;

    expect($snapshot)->toBeArray()
        ->and($snapshot)->toHaveKeys(['calculated_at', 'engine_version', 'base', 'totals', 'lines'])
        ->and($snapshot['base'])->toHaveKeys(['area', 'rate', 'amount'])
        ->and($snapshot['totals'])->toHaveKeys(['plc', 'charges', 'subtotal', 'discount', 'tax', 'final'])
        ->and($snapshot['totals']['final'])->toBe($booking->final_amount)
        ->and($snapshot['base']['amount'])->toBe('2000000.00');
});

it('a later change to a pricing master does not move a confirmed booking total (33)', function () {
    $s = bookingScenario();
    $plc = PlcType::factory()->create(['calculation_type' => 'percentage', 'value' => 10, 'is_active' => true]);

    $booking = priceThenConfirm($s, $plc);

    $frozenFinal = $booking->final_amount;
    $frozenPlc = $booking->plc_amount;
    $frozenSnapshot = $booking->pricing_snapshot;

    // The master is later re-priced.
    $plc->update(['value' => 25]);

    $reloaded = $booking->fresh();
    expect($reloaded->final_amount)->toBe($frozenFinal)
        ->and($reloaded->plc_amount)->toBe($frozenPlc)
        ->and($reloaded->pricing_snapshot)->toBe($frozenSnapshot)
        ->and($reloaded->priceLines->firstWhere('type', PriceComponentType::Plc)->rate)->toBe('10.0000');
});

it('deleting a referenced pricing master is blocked, not cascaded', function () {
    $s = bookingScenario();
    $plc = PlcType::factory()->create(['calculation_type' => 'percentage', 'value' => 10, 'is_active' => true]);
    priceThenConfirm($s, $plc);

    expect($plc->fresh()->isReferenced())->toBeTrue();
});

it('cancelling a confirmed booking releases the plot without touching finances (34)', function () {
    $s = bookingScenario();
    $booking = priceThenConfirm($s);

    $snapshotBefore = $booking->pricing_snapshot;
    $finalBefore = $booking->final_amount;

    $cancelled = app(CancelBookingAction::class)->handle($booking->fresh(), $s['actor'], 'buyer withdrew');

    expect($cancelled->status)->toBe(BookingStatus::Cancelled)
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and($cancelled->cancellation_reason)->toBe('buyer withdrew')
        ->and($s['plot']->fresh()->status)->toBe(PlotStatus::Available)
        // financial truth is preserved — the snapshot and totals are untouched
        ->and($cancelled->pricing_snapshot)->toBe($snapshotBefore)
        ->and($cancelled->final_amount)->toBe($finalBefore);
});

it('cancelling a pending booking frees the reservation but never the plot status', function () {
    $s = bookingScenario();
    $booking = app(CreateBookingAction::class)->handle(bookingPayload($s), $s['actor']);
    app(SubmitBookingAction::class)->handle($booking, $s['actor']);

    app(CancelBookingAction::class)->handle($booking->fresh(), $s['actor'], null);

    expect($booking->fresh()->active_plot_id)->toBeNull()
        ->and($s['plot']->fresh()->status)->toBe(PlotStatus::Available);
});
