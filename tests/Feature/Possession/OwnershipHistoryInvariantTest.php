<?php

declare(strict_types=1);

use App\Actions\Transfer\CompleteTransferAction;
use App\Actions\Transfer\CreateTransferRequestAction;
use App\Actions\Transfer\TransferWorkflowAction;
use App\Enums\TransferType;
use App\Models\Buyer;
use App\Models\PlotOwnershipHistory;
use App\Services\Ownership\PlotOwnershipService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    Storage::fake('documents');
});

/**
 * F-M10-1 / DB-2 — the database, not just PlotOwnershipService, enforces that a
 * buyer holds at most one OPEN ownership period per booking.
 */
it('rejects a second open ownership period for the same booking + buyer at the database', function () {
    $s = confirmedBookingScenario();
    $buyer = Buyer::factory()->create(['status' => 'active']);

    PlotOwnershipHistory::create([
        'plot_id' => $s['booking']->plot_id, 'booking_id' => $s['booking']->id, 'buyer_id' => $buyer->id,
        'ownership_type' => 'allotment', 'is_primary' => true, 'ownership_percentage' => 100,
        'started_at' => now()->subMonth(), 'ended_at' => null,
    ]);

    expect(fn () => PlotOwnershipHistory::create([
        'plot_id' => $s['booking']->plot_id, 'booking_id' => $s['booking']->id, 'buyer_id' => $buyer->id,
        'ownership_type' => 'transfer', 'is_primary' => true, 'ownership_percentage' => 100,
        'started_at' => now(), 'ended_at' => null,
    ]))->toThrow(QueryException::class);
});

it('allows several open periods on a co-owned booking (one per co-owner)', function () {
    $s = confirmedBookingScenario();
    $a = Buyer::factory()->create(['status' => 'active']);
    $b = Buyer::factory()->create(['status' => 'active']);

    foreach ([$a, $b] as $buyer) {
        PlotOwnershipHistory::create([
            'plot_id' => $s['booking']->plot_id, 'booking_id' => $s['booking']->id, 'buyer_id' => $buyer->id,
            'ownership_type' => 'allotment', 'is_primary' => $buyer->is($a), 'ownership_percentage' => 50,
            'started_at' => now()->subMonth(), 'ended_at' => null,
        ]);
    }

    expect(PlotOwnershipHistory::where('booking_id', $s['booking']->id)->whereNull('ended_at')->count())->toBe(2);
});

it('allows a buyer to re-own the same booking once the earlier period is closed', function () {
    $s = confirmedBookingScenario();
    $buyer = Buyer::factory()->create(['status' => 'active']);

    $first = PlotOwnershipHistory::create([
        'plot_id' => $s['booking']->plot_id, 'booking_id' => $s['booking']->id, 'buyer_id' => $buyer->id,
        'ownership_type' => 'allotment', 'is_primary' => true, 'ownership_percentage' => 100,
        'started_at' => now()->subMonths(2), 'ended_at' => null,
    ]);
    $first->update(['ended_at' => now()->subMonth()]);

    $second = PlotOwnershipHistory::create([
        'plot_id' => $s['booking']->plot_id, 'booking_id' => $s['booking']->id, 'buyer_id' => $buyer->id,
        'ownership_type' => 'transfer', 'is_primary' => true, 'ownership_percentage' => 100,
        'started_at' => now(), 'ended_at' => null,
    ]);

    expect($second->exists)->toBeTrue()
        ->and(PlotOwnershipHistory::where('booking_id', $s['booking']->id)->count())->toBe(2);
});

it('does not break the normal transfer flow (old period closed, new one opened)', function () {
    $s = transferReadyScenario();
    app(PlotOwnershipService::class)->ensureAllotment($s['booking']->fresh());

    $t = app(CreateTransferRequestAction::class)->handle($s['booking']->fresh(), TransferType::SaleTransfer, [
        'new_buyer_id' => $s['newBuyer']->id,
    ], possessionOfficer());
    $officer = possessionOfficer();
    app(TransferWorkflowAction::class)->submit($t, $officer);
    app(TransferWorkflowAction::class)->startReview($t->fresh(), $officer);
    app(TransferWorkflowAction::class)->approve($t->fresh(), $officer);
    app(CompleteTransferAction::class)->handle($t->fresh(), $officer);

    $open = PlotOwnershipHistory::where('booking_id', $s['booking']->id)->whereNull('ended_at')->get();

    expect($open)->toHaveCount(1)
        ->and($open->first()->buyer_id)->toBe($s['newBuyer']->id);
});
