<?php

declare(strict_types=1);

use App\Actions\Documents\RejectDocumentAction;
use App\Actions\Documents\UploadDocumentAction;
use App\Actions\Documents\VerifyDocumentAction;
use App\Actions\Transfer\CreateTransferRequestAction;
use App\Enums\TransferType;
use App\Livewire\Bookings\BookingDocuments;
use App\Livewire\Bookings\BookingTransfers;
use App\Models\Buyer;
use App\Models\Document;
use App\Models\TransferRequest;
use App\Services\Transfer\TransferEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    seedDocumentMasters();
    Storage::fake('documents');
});

function draftSaleTransfer(array $s): TransferRequest
{
    $newBuyer = Buyer::factory()->create(['status' => 'active']);

    return app(CreateTransferRequestAction::class)->handle($s['booking']->fresh(), TransferType::SaleTransfer, [
        'new_buyer_id' => $newBuyer->id, 'reason' => 'Resale',
    ], possessionOfficer());
}

/*
| The Transfers screen no longer has an upload UI for Transfer Application /
| Consent / ID Proof (product requirement: Plot Transfer only, no transfer
| documents). The underlying document types, TransferEligibilityService's
| ownership-transfer document check, and any already-uploaded document rows
| are all left completely intact — see BookingTransfers's class docblock.
*/

it('no longer shows Transfer Application / Consent / ID Proof anywhere on the simplified Transfers screen', function () {
    $s = confirmedBookingScenario();
    draftSaleTransfer($s);

    Livewire::actingAs(possessionOfficer())
        ->test(BookingTransfers::class, ['booking' => $s['booking']->fresh()])
        ->assertOk()
        ->assertDontSee('Transfer Application')
        ->assertDontSee('Transfer Consent')
        ->assertDontSee('Transfer ID Proof');

    Livewire::actingAs(possessionOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']->fresh()])
        ->assertOk()
        ->assertDontSee('Transfer Application')
        ->assertDontSee('Transfer Consent')
        ->assertDontSee('Transfer ID Proof');
});

it('the Transfers screen no longer exposes any transfer-document upload/reject/delete methods', function () {
    $s = confirmedBookingScenario();
    draftSaleTransfer($s);

    expect(method_exists(BookingTransfers::class, 'uploadTransferDocument'))->toBeFalse()
        ->and(method_exists(BookingTransfers::class, 'rejectTransferDocument'))->toBeFalse()
        ->and(method_exists(BookingTransfers::class, 'deleteTransferDocument'))->toBeFalse()
        ->and(in_array(Livewire\WithFileUploads::class, class_uses(BookingTransfers::class), true))->toBeFalse();
});

it('a pre-existing transfer document is preserved untouched even though the UI no longer manages it', function () {
    $s = confirmedBookingScenario();
    draftSaleTransfer($s);
    $officer = possessionOfficer();

    $doc = app(UploadDocumentAction::class)->handle($s['booking'], docType('TRANSFER_APPLICATION'), fakeDocument(), $officer);

    expect(Document::find($doc->id))->not->toBeNull()
        ->and(Document::find($doc->id)->currentVersion)->not->toBeNull();
});

it('verifying all three transfer documents satisfies TransferEligibilityService\'s document check (old ownership-transfer flow, unchanged)', function () {
    $s = confirmedBookingScenario();
    $transfer = draftSaleTransfer($s);
    $officer = possessionOfficer();

    foreach (['TRANSFER_APPLICATION', 'TRANSFER_CONSENT', 'TRANSFER_ID_PROOF'] as $code) {
        $doc = app(UploadDocumentAction::class)->handle($s['booking'], docType($code), fakeDocument("{$code}.pdf"), $officer);
        app(VerifyDocumentAction::class)->handle($doc, $officer);
    }

    $result = app(TransferEligibilityService::class)->evaluate($transfer->fresh());

    $docCheck = collect($result->checks)->firstWhere('key', 'documents_verified');
    expect($docCheck['passed'])->toBeTrue();
});

it('rejecting a transfer document keeps TransferEligibilityService\'s document check failing (old ownership-transfer flow, unchanged)', function () {
    $s = confirmedBookingScenario();
    $transfer = draftSaleTransfer($s);
    $officer = possessionOfficer();
    $rejecter = makeUser(permissions: [
        'transfer.view', 'transfer.create', 'transfer.review', 'transfer.approve',
        'documents.view', 'documents.upload', 'documents.reject', 'bookings.view',
    ]);

    $doc = app(UploadDocumentAction::class)->handle($s['booking'], docType('TRANSFER_APPLICATION'), fakeDocument(), $officer);
    app(RejectDocumentAction::class)->handle($doc, 'Wrong form', $rejecter);

    expect($doc->fresh()->status->value)->toBe('rejected');

    $result = app(TransferEligibilityService::class)->evaluate($transfer->fresh());
    $docCheck = collect($result->checks)->firstWhere('key', 'documents_verified');
    expect($docCheck['passed'])->toBeFalse();
});
