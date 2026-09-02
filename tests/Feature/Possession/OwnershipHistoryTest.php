<?php

declare(strict_types=1);

use App\Actions\Transfer\CompleteTransferAction;
use App\Actions\Transfer\CreateTransferRequestAction;
use App\Actions\Transfer\TransferWorkflowAction;
use App\Enums\OwnershipType;
use App\Enums\TransferRequestStatus;
use App\Enums\TransferType;
use App\Exceptions\DomainException;
use App\Models\BookingBuyer;
use App\Models\Buyer;
use App\Models\PlotOwnershipHistory;
use App\Models\TransferRequest;
use App\Services\Ownership\PlotOwnershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    Storage::fake('documents');
});

function completedTransfer(array $s): TransferRequest
{
    $t = app(CreateTransferRequestAction::class)->handle($s['booking']->fresh(), TransferType::SaleTransfer, [
        'new_buyer_id' => $s['newBuyer']->id,
    ], possessionOfficer());
    $officer = possessionOfficer();
    app(TransferWorkflowAction::class)->submit($t, $officer);
    app(TransferWorkflowAction::class)->startReview($t->fresh(), $officer);
    app(TransferWorkflowAction::class)->approve($t->fresh(), $officer);

    return app(CompleteTransferAction::class)->handle($t->fresh(), $officer);
}

/*
| OWNERSHIP (24-29)
*/

it('materialises the original allotment from booking_buyers (24, 26)', function () {
    $s = transferReadyScenario();

    app(PlotOwnershipService::class)->ensureAllotment($s['booking']->fresh());

    $periods = PlotOwnershipHistory::where('booking_id', $s['booking']->id)->get();
    expect($periods)->toHaveCount(1)
        ->and($periods->first()->ownership_type)->toBe(OwnershipType::Allotment)
        ->and($periods->first()->buyer_id)->toBe($s['buyer']->id)
        ->and($periods->first()->ended_at)->toBeNull();

    // idempotent
    app(PlotOwnershipService::class)->ensureAllotment($s['booking']->fresh());
    expect(PlotOwnershipHistory::where('booking_id', $s['booking']->id)->count())->toBe(1);
});

it('closes the old period and opens the new one on completion (24, 25, 26, 27)', function () {
    $s = transferReadyScenario();
    completedTransfer($s);

    $all = PlotOwnershipHistory::where('booking_id', $s['booking']->id)->orderBy('id')->get();
    expect($all)->toHaveCount(2);

    $old = $all->first();
    $new = $all->last();

    expect($old->buyer_id)->toBe($s['buyer']->id)
        ->and($old->ended_at)->not->toBeNull()          // old owner preserved, just closed (25)
        ->and($old->ownership_type)->toBe(OwnershipType::Allotment)
        ->and($new->buyer_id)->toBe($s['newBuyer']->id) // new owner created (26)
        ->and($new->ended_at)->toBeNull()
        ->and($new->started_at->greaterThanOrEqualTo($old->ended_at))->toBeTrue() // correct dates (27)
        ->and($new->ownership_type)->toBe(OwnershipType::Transfer);
});

it('never mutates the historical booking_buyers pivot on transfer (25)', function () {
    $s = transferReadyScenario();
    $pivotBefore = BookingBuyer::where('booking_id', $s['booking']->id)->get()->toArray();

    completedTransfer($s);

    expect(BookingBuyer::where('booking_id', $s['booking']->id)->get()->toArray())->toEqual($pivotBefore);
});

it('keeps exactly one active period per booking after a transfer (28)', function () {
    $s = transferReadyScenario();
    completedTransfer($s);

    expect(PlotOwnershipHistory::where('booking_id', $s['booking']->id)->whereNull('ended_at')->count())->toBe(1);
});

it('rejects a transfer whose incoming buyer already owns the plot (28)', function () {
    $s = transferReadyScenario();

    expect(fn () => app(CreateTransferRequestAction::class)->handle($s['booking']->fresh(), TransferType::SaleTransfer, [
        'new_buyer_id' => $s['buyer']->id,
    ], possessionOfficer()))->toThrow(DomainException::class);
});

it('serialises two approved transfers so only one completes (29)', function () {
    $s = transferReadyScenario();

    // first transfer through to completed
    completedTransfer($s);

    // a stale second approved transfer cannot also complete
    $stale = TransferRequest::factory()->forBooking($s['booking'])->status(TransferRequestStatus::Approved)->create([
        'new_buyer_id' => Buyer::factory()->create(['status' => 'active'])->id,
        'current_buyer_id' => $s['buyer']->id,
        'transfer_type' => TransferType::SaleTransfer->value,
        'approved_at' => now()->subMinute(),
    ]);

    expect(fn () => app(CompleteTransferAction::class)->handle($stale, possessionOfficer()))
        ->toThrow(DomainException::class);

    expect(PlotOwnershipHistory::where('booking_id', $s['booking']->id)->whereNull('ended_at')->count())->toBe(1);
});

it('leaves ownership untouched for a nominee-change transfer', function () {
    $s = transferReadyScenario();

    $t = app(CreateTransferRequestAction::class)->handle($s['booking']->fresh(), TransferType::NomineeChange, [], possessionOfficer());
    $officer = possessionOfficer();
    app(TransferWorkflowAction::class)->submit($t, $officer);
    app(TransferWorkflowAction::class)->startReview($t->fresh(), $officer);
    app(TransferWorkflowAction::class)->approve($t->fresh(), $officer);
    app(CompleteTransferAction::class)->handle($t->fresh(), $officer);

    // only the allotment period exists, still open
    $periods = PlotOwnershipHistory::where('booking_id', $s['booking']->id)->get();
    expect($periods)->toHaveCount(1)
        ->and($periods->first()->ownership_type)->toBe(OwnershipType::Allotment);
});
