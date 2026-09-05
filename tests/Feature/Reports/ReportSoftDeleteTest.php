<?php

declare(strict_types=1);

use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Plot;
use App\Services\Reports\CollectionAnalytics;
use App\Services\Reports\InventoryAnalytics;
use App\Services\Reports\SalesAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

/**
 * F-REP-2 — a report must use the same data truth as the operational module:
 * a soft-deleted record is invisible to Eloquent and must be invisible to the
 * raw report queries too.
 */
function wideFilter()
{
    return misFilter(['from' => now()->subYears(2)->toDateString(), 'to' => now()->addYears(2)->toDateString()]);
}

it('excludes a soft-deleted confirmed booking from the sales + collections + inventory reports', function () {
    $keep = confirmedBookingScenario('1000000');
    activePlanFor($keep['booking'], $keep['actor'], [
        ['type' => 'amount', 'value' => '1000000', 'due_date' => now()->subMonth()->toDateString()],
    ]);

    $drop = confirmedBookingScenario('5000000');
    activePlanFor($drop['booking'], $drop['actor'], [
        ['type' => 'amount', 'value' => '5000000', 'due_date' => now()->subMonth()->toDateString()],
    ]);

    $filters = wideFilter();
    $collection = app(CollectionAnalytics::class);
    $sales = app(SalesAnalytics::class);

    $beforeReceivable = $collection->kpis($filters)['receivable'];
    $beforeValue = $collection->reconciliation($filters)['bookingValue'];

    // Soft-delete the ₹50 L booking (skip the CancelBookingAction guard —
    // this is the "operator data fix / GDPR erase" path the finding is about).
    Booking::withoutEvents(fn () => $drop['booking']->delete());

    $afterReceivable = app(CollectionAnalytics::class)->kpis($filters)['receivable'];
    $afterValue = app(CollectionAnalytics::class)->reconciliation($filters)['bookingValue'];

    expect(round($beforeReceivable - $afterReceivable, 2))->toBe(5000000.0)
        ->and(round($beforeValue - $afterValue, 2))->toBe(5000000.0);
});

it('excludes a soft-deleted payment from collections cash figures', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking'], $s['actor'], [
        ['type' => 'amount', 'value' => '1000000', 'due_date' => now()->subMonth()->toDateString()],
    ]);
    $p = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id,
        'amount' => '400000', 'payment_date' => now()->toDateString(),
    ], $s['actor']);
    app(VerifyPaymentAction::class)->handle($p, PaymentStatus::Success, $s['actor']);

    $filters = wideFilter();
    $before = app(CollectionAnalytics::class)->reconciliation($filters)['cashCollected'];

    Payment::withoutEvents(fn () => $p->fresh()->delete());

    $after = app(CollectionAnalytics::class)->reconciliation($filters)['cashCollected'];

    expect(round($before - $after, 2))->toBe(400000.0);
});

it('excludes a soft-deleted plot from inventory counts', function () {
    $s = bookingScenario(); // gives project + block + an AVAILABLE plot
    $extraPlot = Plot::factory()->create([
        'project_id' => $s['project']->id, 'block_id' => $s['block']->id, 'status' => 'available',
    ]);

    $filters = misFilter([
        'from' => now()->subYears(2)->toDateString(), 'to' => now()->addYears(2)->toDateString(),
        'projectId' => $s['project']->id,
    ]);

    $before = app(InventoryAnalytics::class)->inventoryKpis($filters);

    Plot::withoutEvents(fn () => $extraPlot->delete());

    $after = app(InventoryAnalytics::class)->inventoryKpis($filters);

    expect($after['total'])->toBe($before['total'] - 1);
});
