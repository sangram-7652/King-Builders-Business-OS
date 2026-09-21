<?php

declare(strict_types=1);

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

it('shows the three transfer documents on the Transfers screen, not on Booking Documents', function () {
    $s = confirmedBookingScenario();
    draftSaleTransfer($s);

    Livewire::actingAs(possessionOfficer())
        ->test(BookingTransfers::class, ['booking' => $s['booking']->fresh()])
        ->assertOk()
        ->assertSee('Transfer Application')
        ->assertSee('Transfer Consent')
        ->assertSee('Transfer ID Proof');

    Livewire::actingAs(possessionOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']->fresh()])
        ->assertOk()
        ->assertDontSee('Transfer Application')
        ->assertDontSee('Transfer Consent')
        ->assertDontSee('Transfer ID Proof');
});

it('uploads a transfer document from the Transfers screen', function () {
    $s = confirmedBookingScenario();
    draftSaleTransfer($s);

    Livewire::actingAs(possessionOfficer())
        ->test(BookingTransfers::class, ['booking' => $s['booking']])
        ->set('transferFiles.'.docType('TRANSFER_APPLICATION')->id, fakeDocument('application.pdf'))
        ->assertHasNoErrors();

    $doc = Document::query()->where('documentable_id', $s['booking']->id)
        ->where('document_type_id', docType('TRANSFER_APPLICATION')->id)
        ->firstOrFail();

    expect($doc->currentVersion)->not->toBeNull();
});

it('verifying all three transfer documents satisfies TransferEligibilityService\'s document check', function () {
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

it('rejecting a transfer document keeps TransferEligibilityService\'s document check failing', function () {
    $s = confirmedBookingScenario();
    $transfer = draftSaleTransfer($s);
    $officer = possessionOfficer();
    $rejecter = makeUser(permissions: [
        'transfer.view', 'transfer.create', 'transfer.review', 'transfer.approve',
        'documents.view', 'documents.upload', 'documents.reject', 'bookings.view',
    ]);

    $doc = app(UploadDocumentAction::class)->handle($s['booking'], docType('TRANSFER_APPLICATION'), fakeDocument(), $officer);

    Livewire::actingAs($rejecter)
        ->test(BookingTransfers::class, ['booking' => $s['booking']->fresh()])
        ->call('openRejectTransferDocument', $doc->id)
        ->set('transferDocRejectReason', 'Wrong form')
        ->call('rejectTransferDocument')
        ->assertHasNoErrors();

    expect($doc->fresh()->status->value)->toBe('rejected');

    $result = app(TransferEligibilityService::class)->evaluate($transfer->fresh());
    $docCheck = collect($result->checks)->firstWhere('key', 'documents_verified');
    expect($docCheck['passed'])->toBeFalse();
});

it('deletes an unverified transfer document', function () {
    $s = confirmedBookingScenario();
    draftSaleTransfer($s);
    $officer = possessionOfficer();
    $deleter = makeUser(permissions: [
        'transfer.view', 'transfer.create', 'documents.view', 'documents.upload', 'documents.delete', 'bookings.view',
    ]);

    $doc = app(UploadDocumentAction::class)->handle($s['booking'], docType('TRANSFER_CONSENT'), fakeDocument(), $officer);

    Livewire::actingAs($deleter)
        ->test(BookingTransfers::class, ['booking' => $s['booking']->fresh()])
        ->call('deleteTransferDocument', $doc->id);

    expect(Document::find($doc->id))->toBeNull();
});

it('requires documents.upload to add a transfer document', function () {
    $s = confirmedBookingScenario();
    draftSaleTransfer($s);
    $noUpload = makeUser(permissions: ['transfer.view', 'transfer.create', 'documents.view', 'bookings.view']);

    Livewire::actingAs($noUpload)
        ->test(BookingTransfers::class, ['booking' => $s['booking']])
        ->set('transferFiles.'.docType('TRANSFER_APPLICATION')->id, fakeDocument());

    expect(Document::query()->where('documentable_id', $s['booking']->id)
        ->where('document_type_id', docType('TRANSFER_APPLICATION')->id)->exists())->toBeFalse();
});
