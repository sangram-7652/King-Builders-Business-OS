<?php

declare(strict_types=1);

use App\Actions\Transfer\CompleteTransferAction;
use App\Actions\Transfer\CreateTransferRequestAction;
use App\Actions\Transfer\RecordBuyerNomineeAction;
use App\Actions\Transfer\TransferWorkflowAction;
use App\Enums\TransferRequestStatus;
use App\Enums\TransferType;
use App\Exceptions\DomainException;
use App\Models\BuyerNominee;
use App\Models\Document;
use App\Models\PlotOwnershipHistory;
use App\Models\TransferRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    Storage::fake('documents');
});

function draftTransfer(array $s, TransferType $type = TransferType::SaleTransfer): TransferRequest
{
    return app(CreateTransferRequestAction::class)->handle($s['booking']->fresh(), $type, [
        'new_buyer_id' => $s['newBuyer']->id, 'reason' => 'Resale',
    ], possessionOfficer());
}

function approvedTransferInReview(array $s): TransferRequest
{
    $t = draftTransfer($s);
    $officer = possessionOfficer();
    app(TransferWorkflowAction::class)->submit($t, $officer);

    return app(TransferWorkflowAction::class)->startReview($t->fresh(), $officer);
}

function approvedTransfer(array $s): TransferRequest
{
    return app(TransferWorkflowAction::class)->approve(approvedTransferInReview($s)->fresh(), possessionOfficer());
}

/*
| TRANSFER (15-23)
*/

it('creates a draft transfer request with a distinct number (15)', function () {
    $s = transferReadyScenario();

    $a = draftTransfer($s);
    expect($a->status)->toBe(TransferRequestStatus::Draft)
        ->and($a->request_number)->toStartWith('TRF-')
        ->and($a->current_buyer_id)->toBe($s['buyer']->id)
        ->and($a->new_buyer_id)->toBe($s['newBuyer']->id);

    // second buyer + booking for a distinct number
    $s2 = transferReadyScenario();
    $b = draftTransfer($s2);
    expect($b->request_number)->not->toBe($a->request_number);
});

it('rejects a second open transfer on the same booking (15)', function () {
    $s = transferReadyScenario();
    draftTransfer($s);

    expect(fn () => draftTransfer($s))->toThrow(DomainException::class);
});

it('submits and starts review (16)', function () {
    $s = transferReadyScenario();
    $t = draftTransfer($s);
    $officer = possessionOfficer();

    $t = app(TransferWorkflowAction::class)->submit($t, $officer);
    expect($t->status)->toBe(TransferRequestStatus::Submitted);

    $t = app(TransferWorkflowAction::class)->startReview($t->fresh(), $officer);
    expect($t->status)->toBe(TransferRequestStatus::UnderReview);
});

it('cycles through documents-pending and back (17)', function () {
    $s = transferReadyScenario();
    $t = approvedTransferInReview($s);
    $officer = possessionOfficer();

    $t = app(TransferWorkflowAction::class)->requestDocuments($t, $officer);
    expect($t->status)->toBe(TransferRequestStatus::DocumentsPending);

    $t = app(TransferWorkflowAction::class)->backToReview($t->fresh(), $officer);
    expect($t->status)->toBe(TransferRequestStatus::UnderReview);
});

it('blocks approval when the financial gate is not met and no waiver (18)', function () {
    $s = transferReadyScenario();
    config()->set('transfer.financial.block_on_outstanding', true);
    config()->set('transfer.financial.max_outstanding', '0');

    $t = approvedTransferInReview($s);

    expect(fn () => app(TransferWorkflowAction::class)->approve($t->fresh(), possessionOfficer()))
        ->toThrow(DomainException::class);

    // waiver reason lets it through
    $t = app(TransferWorkflowAction::class)->approve($t->fresh(), possessionOfficer(), ['financial_waiver_reason' => 'Paid outside system']);
    expect($t->status)->toBe(TransferRequestStatus::Approved);
});

it('blocks approval when required transfer documents are unverified (19)', function () {
    $s = transferReadyScenario();
    Document::where('documentable_id', $s['booking']->id)->update(['status' => 'uploaded']);

    $t = approvedTransferInReview($s);
    expect(fn () => app(TransferWorkflowAction::class)->approve($t->fresh(), possessionOfficer()))
        ->toThrow(DomainException::class);
});

it('approves an eligible transfer and stores the snapshot (20)', function () {
    $s = transferReadyScenario();
    $t = approvedTransfer($s);

    expect($t->status)->toBe(TransferRequestStatus::Approved)
        ->and($t->approved_by)->not->toBeNull()
        ->and($t->financial_snapshot)->toHaveKey('eligible');
});

it('requires a reason to reject (21)', function () {
    $s = transferReadyScenario();
    $t = approvedTransferInReview($s);

    expect(fn () => app(TransferWorkflowAction::class)->reject($t, '  ', possessionOfficer()))
        ->toThrow(DomainException::class);

    $t = app(TransferWorkflowAction::class)->reject($t->fresh(), 'Documents forged', possessionOfficer());
    expect($t->status)->toBe(TransferRequestStatus::Rejected)
        ->and($t->rejection_reason)->toBe('Documents forged');
});

it('completes an approved transfer and moves ownership (22)', function () {
    $s = transferReadyScenario();
    $t = approvedTransfer($s);

    $t = app(CompleteTransferAction::class)->handle($t, possessionOfficer());

    expect($t->status)->toBe(TransferRequestStatus::Completed)
        ->and($t->completed_at)->not->toBeNull();

    $active = PlotOwnershipHistory::where('booking_id', $s['booking']->id)->whereNull('ended_at')->get();
    expect($active)->toHaveCount(1)
        ->and($active->first()->buyer_id)->toBe($s['newBuyer']->id);
});

it('never records a duplicate transfer completion (23)', function () {
    $s = transferReadyScenario();
    $t = approvedTransfer($s);

    $first = app(CompleteTransferAction::class)->handle($t, possessionOfficer());
    $again = app(CompleteTransferAction::class)->handle($t->fresh(), possessionOfficer());

    expect($again->completed_at->equalTo($first->completed_at))->toBeTrue()
        ->and(PlotOwnershipHistory::where('booking_id', $s['booking']->id)->where('ownership_type', 'transfer')->count())->toBe(1);
});

it('cannot complete a transfer that is not approved', function () {
    $s = transferReadyScenario();
    $t = draftTransfer($s);

    expect(fn () => app(CompleteTransferAction::class)->handle($t, possessionOfficer()))
        ->toThrow(DomainException::class);
});

it('records a nominee without touching ownership history', function () {
    $s = transferReadyScenario();
    $before = PlotOwnershipHistory::count();

    $n1 = app(RecordBuyerNomineeAction::class)->handle($s['buyer'], ['name' => 'Ravi', 'relation' => 'Son'], possessionOfficer());
    $n2 = app(RecordBuyerNomineeAction::class)->handle($s['buyer'], ['name' => 'Meera', 'relation' => 'Daughter'], possessionOfficer());

    expect($n1->fresh()->status)->toBe(BuyerNominee::STATUS_SUPERSEDED)
        ->and($n1->fresh()->superseded_by)->toBe($n2->id)
        ->and($n2->status)->toBe(BuyerNominee::STATUS_ACTIVE)
        ->and(PlotOwnershipHistory::count())->toBe($before);
});
