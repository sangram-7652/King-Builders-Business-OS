<?php

declare(strict_types=1);

use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Actions\Transfer\CompleteTransferAction;
use App\Actions\Transfer\CreateTransferRequestAction;
use App\Actions\Transfer\TransferWorkflowAction;
use App\Enums\OwnershipType;
use App\Enums\PaymentStatus;
use App\Enums\PlotStatus;
use App\Enums\PossessionCaseStatus;
use App\Enums\TransferRequestStatus;
use App\Enums\TransferType;
use App\Exceptions\DomainException;
use App\Models\Block;
use App\Models\Booking;
use App\Models\Document;
use App\Models\Payment;
use App\Models\Plot;
use App\Models\PlotOwnershipHistory;
use App\Models\PossessionCase;
use App\Models\Project;
use App\Models\TransferRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    Storage::fake('documents');
});

/** @param  array{booking: Booking, newPlot: Plot}  $s */
function draftPlotTransfer(array $s, ?string $reason = 'Customer requested plot change'): TransferRequest
{
    return app(CreateTransferRequestAction::class)->handle($s['booking']->fresh(), TransferType::PlotTransfer, [
        'new_plot_id' => $s['newPlot']->id, 'reason' => $reason,
    ], possessionOfficer());
}

function plotTransferInReview(array $s): TransferRequest
{
    $t = draftPlotTransfer($s);
    $officer = possessionOfficer();
    app(TransferWorkflowAction::class)->submit($t, $officer);

    return app(TransferWorkflowAction::class)->startReview($t->fresh(), $officer);
}

function approvedPlotTransfer(array $s): TransferRequest
{
    return app(TransferWorkflowAction::class)->approve(plotTransferInReview($s)->fresh(), possessionOfficer());
}

// --- 1-5: the happy path through every state ------------------------------

it('1: creates a draft plot transfer with the target plot recorded', function () {
    $s = plotTransferReadyScenario();

    $t = draftPlotTransfer($s);

    expect($t->status)->toBe(TransferRequestStatus::Draft)
        ->and($t->transfer_type)->toBe(TransferType::PlotTransfer)
        ->and($t->request_number)->toStartWith('TRF-')
        ->and($t->plot_id)->toBe($s['oldPlot']->id)
        ->and($t->new_plot_id)->toBe($s['newPlot']->id)
        ->and($t->new_buyer_id)->toBeNull() // buyer never changes for a plot transfer
        ->and($t->reason)->toBe('Customer requested plot change');
});

it('2: submits a plot transfer', function () {
    $s = plotTransferReadyScenario();
    $t = draftPlotTransfer($s);

    $t = app(TransferWorkflowAction::class)->submit($t, possessionOfficer());

    expect($t->status)->toBe(TransferRequestStatus::Submitted);
});

it('3: starts review on a plot transfer', function () {
    $s = plotTransferReadyScenario();
    $t = app(TransferWorkflowAction::class)->submit(draftPlotTransfer($s), possessionOfficer());

    $t = app(TransferWorkflowAction::class)->startReview($t->fresh(), possessionOfficer());

    expect($t->status)->toBe(TransferRequestStatus::UnderReview);
});

it('4: approves a plot transfer — no financial or document checks apply', function () {
    $s = plotTransferReadyScenario();

    $t = app(TransferWorkflowAction::class)->approve(plotTransferInReview($s)->fresh(), possessionOfficer());

    expect($t->status)->toBe(TransferRequestStatus::Approved)
        ->and($t->financial_snapshot)->toHaveKey('eligible')
        ->and($t->financial_snapshot['eligible'])->toBeTrue();
});

it('5: completes an approved plot transfer', function () {
    $s = plotTransferReadyScenario();
    $t = approvedPlotTransfer($s);

    $t = app(CompleteTransferAction::class)->handle($t, possessionOfficer());

    expect($t->status)->toBe(TransferRequestStatus::Completed)
        ->and($t->completed_at)->not->toBeNull()
        ->and($t->completed_by)->not->toBeNull();
});

// --- 6-9: the plot swap itself, and what must NOT change -------------------

it('6: releases the old plot back to Available', function () {
    $s = plotTransferReadyScenario();
    $t = approvedPlotTransfer($s);
    app(CompleteTransferAction::class)->handle($t, possessionOfficer());

    expect($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Available);
});

it('7: books the new plot', function () {
    $s = plotTransferReadyScenario();
    $t = approvedPlotTransfer($s);
    app(CompleteTransferAction::class)->handle($t, possessionOfficer());

    expect($s['newPlot']->fresh()->status)->toBe(PlotStatus::Booked);
});

it('8: the booking ID never changes — same booking, new plot', function () {
    $s = plotTransferReadyScenario();
    $bookingId = $s['booking']->id;
    $bookingNumber = $s['booking']->booking_number;
    $t = approvedPlotTransfer($s);

    app(CompleteTransferAction::class)->handle($t, possessionOfficer());

    $fresh = Booking::find($bookingId);
    expect($fresh)->not->toBeNull()
        ->and($fresh->id)->toBe($bookingId)
        ->and($fresh->booking_number)->toBe($bookingNumber)
        ->and($fresh->plot_id)->toBe($s['newPlot']->id)
        ->and($fresh->block_id)->toBe($s['newPlot']->block_id)
        ->and($fresh->project_id)->toBe($s['newPlot']->project_id)
        ->and(Booking::count())->toBe(1); // no second booking was created
});

it('9: the buyer is completely unchanged by a plot transfer', function () {
    $s = plotTransferReadyScenario();
    $buyerId = $s['buyer']->id;
    $t = approvedPlotTransfer($s);

    app(CompleteTransferAction::class)->handle($t, possessionOfficer());

    $active = PlotOwnershipHistory::where('booking_id', $s['booking']->id)->whereNull('ended_at')->get();
    expect($active)->toHaveCount(1)
        ->and($active->first()->buyer_id)->toBe($buyerId)
        ->and($active->first()->plot_id)->toBe($s['newPlot']->id)
        ->and($active->first()->ownership_type)->toBe(OwnershipType::PlotChange)
        ->and($s['booking']->fresh()->bookingBuyers()->first()->buyer_id)->toBe($buyerId); // booking_buyers untouched
});

// --- 10-11: payments and documents are completely undisturbed --------------

it('10: existing payments remain attached to the booking, unchanged', function () {
    $s = plotTransferReadyScenario();
    $payment = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id,
        'amount' => '200000', 'payment_date' => now()->toDateString(),
    ], $s['actor']);
    app(VerifyPaymentAction::class)->handle($payment, PaymentStatus::Success, $s['actor']);

    $t = approvedPlotTransfer($s);
    app(CompleteTransferAction::class)->handle($t, possessionOfficer());

    $freshPayment = $payment->fresh();
    expect($freshPayment->booking_id)->toBe($s['booking']->id)
        ->and((string) $freshPayment->amount)->toBe('200000.00')
        ->and($freshPayment->status)->toBe(PaymentStatus::Success)
        ->and(Payment::where('booking_id', $s['booking']->id)->count())->toBe(1);
});

it('11: existing documents remain attached to the booking, unchanged', function () {
    $s = plotTransferReadyScenario();
    seedDocumentMasters();
    $doc = Document::factory()->verified()->forDocumentable($s['booking'])
        ->state(['document_type_id' => docType('TRANSFER_APPLICATION')->id])->create();

    $t = approvedPlotTransfer($s);
    app(CompleteTransferAction::class)->handle($t, possessionOfficer());

    $fresh = $doc->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->documentable_id)->toBe($s['booking']->id)
        ->and($fresh->status->value)->toBe('verified');
});

// --- 12: transfer history records both plots --------------------------------

it('12: records the old and new plot on the completed transfer, and the booking page can show it', function () {
    $s = plotTransferReadyScenario();
    $t = approvedPlotTransfer($s);
    $completed = app(CompleteTransferAction::class)->handle($t, possessionOfficer());

    expect($completed->plot_id)->toBe($s['oldPlot']->id)
        ->and($completed->new_plot_id)->toBe($s['newPlot']->id)
        ->and($completed->plot->plot_number)->toBe($s['oldPlot']->plot_number)
        ->and($completed->newPlot->plot_number)->toBe($s['newPlot']->plot_number);

    // The old plot's relationship is preserved, not destroyed.
    $oldPlotHistory = PlotOwnershipHistory::where('plot_id', $s['oldPlot']->id)->where('booking_id', $s['booking']->id)->first();
    expect($oldPlotHistory)->not->toBeNull()
        ->and($oldPlotHistory->ended_at)->not->toBeNull(); // closed, not deleted
});

// --- 13: cannot even raise a request against an unavailable target plot ----

it('13: cannot select an unavailable plot as the target', function () {
    $s = plotTransferReadyScenario();
    $s['newPlot']->forceFill(['status' => PlotStatus::Booked])->save();

    expect(fn () => draftPlotTransfer($s))->toThrow(DomainException::class);
});

it('13b: cannot target a plot in a different project', function () {
    $s = plotTransferReadyScenario();
    $otherProject = Project::factory()->create();
    $otherBlock = Block::factory()->create(['project_id' => $otherProject->id]);
    $otherPlot = Plot::factory()->create([
        'project_id' => $otherProject->id, 'block_id' => $otherBlock->id, 'status' => PlotStatus::Available->value,
    ]);

    expect(fn () => app(CreateTransferRequestAction::class)->handle($s['booking']->fresh(), TransferType::PlotTransfer, [
        'new_plot_id' => $otherPlot->id, 'reason' => 'Cross-project attempt',
    ], possessionOfficer()))->toThrow(DomainException::class, 'same project');
});

// --- 14: re-checked immediately before completion, never trusted from earlier ---

it('14: cannot complete if the target plot became booked after approval', function () {
    $s = plotTransferReadyScenario();
    $t = approvedPlotTransfer($s);

    // Someone else claims the target plot after approval, before completion.
    $s['newPlot']->forceFill(['status' => PlotStatus::Booked])->save();

    expect(fn () => app(CompleteTransferAction::class)->handle($t->fresh(), possessionOfficer()))
        ->toThrow(DomainException::class);

    // Nothing was silently released in the failed attempt.
    expect($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Booked)
        ->and($s['booking']->fresh()->plot_id)->toBe($s['oldPlot']->id);
});

it('15: cannot complete if the old plot is no longer the booking\'s current plot', function () {
    $s = plotTransferReadyScenario();
    $t = approvedPlotTransfer($s);

    // Booking's plot drifted since approval (e.g. some other process moved it).
    $elsewhere = Plot::factory()->create([
        'project_id' => $s['oldPlot']->project_id, 'block_id' => $s['oldPlot']->block_id, 'status' => PlotStatus::Booked->value,
    ]);
    $s['booking']->forceFill(['plot_id' => $elsewhere->id])->save();

    expect(fn () => app(CompleteTransferAction::class)->handle($t->fresh(), possessionOfficer()))
        ->toThrow(DomainException::class);
});

// --- 16: atomic — a failed completion leaves nothing half-applied ----------

it('16: a failed completion rolls back atomically — no plot is left dangling', function () {
    $s = plotTransferReadyScenario();
    $t = approvedPlotTransfer($s);
    $s['newPlot']->forceFill(['status' => PlotStatus::Booked])->save();

    try {
        app(CompleteTransferAction::class)->handle($t->fresh(), possessionOfficer());
    } catch (DomainException) {
        // expected
    }

    expect($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Booked) // never released
        ->and($s['newPlot']->fresh()->status)->toBe(PlotStatus::Booked) // unchanged by us
        ->and($s['booking']->fresh()->plot_id)->toBe($s['oldPlot']->id) // booking never moved
        ->and($t->fresh()->status)->toBe(TransferRequestStatus::Approved) // request never flipped to Completed
        ->and(PlotOwnershipHistory::where('booking_id', $s['booking']->id)->where('plot_id', $s['newPlot']->id)->exists())->toBeFalse();
});

// --- 17: completion is idempotent -------------------------------------------

it('17: retrying a completed plot transfer is idempotent — no duplicate history, no double swap', function () {
    $s = plotTransferReadyScenario();
    $t = approvedPlotTransfer($s);

    $first = app(CompleteTransferAction::class)->handle($t, possessionOfficer());
    $again = app(CompleteTransferAction::class)->handle($t->fresh(), possessionOfficer());

    expect($again->completed_at->equalTo($first->completed_at))->toBeTrue()
        ->and($s['oldPlot']->fresh()->status)->toBe(PlotStatus::Available)
        ->and($s['newPlot']->fresh()->status)->toBe(PlotStatus::Booked)
        ->and(PlotOwnershipHistory::where('booking_id', $s['booking']->id)->where('ownership_type', OwnershipType::PlotChange->value)->count())->toBe(1);
});

// --- possession guard (F-M10-PLOT) ------------------------------------------

it('blocks approval and completion once a possession case already exists for the booking', function () {
    $s = plotTransferReadyScenario();
    PossessionCase::factory()->create([
        'booking_id' => $s['booking']->id, 'plot_id' => $s['oldPlot']->id, 'status' => PossessionCaseStatus::NotStarted->value,
    ]);

    $t = plotTransferInReview($s);

    expect(fn () => app(TransferWorkflowAction::class)->approve($t->fresh(), possessionOfficer()))
        ->toThrow(DomainException::class);
});
