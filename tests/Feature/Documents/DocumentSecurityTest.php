<?php

declare(strict_types=1);

use App\Actions\Documents\UploadDocumentAction;
use App\Livewire\Buyers\BuyerDocuments;
use App\Models\Buyer;
use App\Models\Document;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    seedDocumentMasters();
    Storage::fake('documents');
});

function uploadedBuyerDoc(): array
{
    $s = confirmedBookingScenario();
    $doc = app(UploadDocumentAction::class)->handle($s['buyer'], docType('AADHAAR'), fakeDocument('aadhaar.pdf'), $s['actor']);

    return [$s, $doc->fresh('currentVersion')];
}

/*
| SECURITY (11-16)
*/

it('keeps the documents disk private and never web-served (11)', function () {
    $disk = config('filesystems.disks.documents');

    expect($disk['visibility'] ?? null)->toBe('private')
        ->and($disk['serve'] ?? true)->toBeFalse()
        ->and($disk)->not->toHaveKey('url');
});

it('stores files under a non-guessable path (12)', function () {
    [, $doc] = uploadedBuyerDoc();

    // path = {snake documentable}/{document id}/v{n}-{40 random}.ext
    expect($doc->currentVersion->path)->toMatch('#^buyer/'.$doc->id.'/v1-[A-Za-z0-9]{40}\.pdf$#');
});

it('serves a stored file only through the authorised download route (13)', function () {
    [$s, $doc] = uploadedBuyerDoc();
    $url = route('documents.download', ['document' => $doc->id, 'version' => $doc->currentVersion->id]);

    // an officer who can see buyers + has documents.download
    $this->actingAs(registryOfficer())->get($url)->assertOk();
});

it('blocks download without the documents.download permission (14)', function () {
    [$s, $doc] = uploadedBuyerDoc();
    $url = route('documents.download', ['document' => $doc->id, 'version' => $doc->currentVersion->id]);

    $noPerm = makeUser(permissions: ['documents.view', 'buyers.view']);
    $this->actingAs($noPerm)->get($url)->assertForbidden();
});

it('prevents IDOR — a user who cannot see the buyer cannot download the buyer document (15)', function () {
    [$s, $doc] = uploadedBuyerDoc();
    $url = route('documents.download', ['document' => $doc->id, 'version' => $doc->currentVersion->id]);

    // holds documents.download but NOT buyers.view → cannot reach the documentable
    $outsider = makeUser(permissions: ['documents.download', 'bookings.view']);
    $this->actingAs($outsider)->get($url)->assertForbidden();
});

it('prevents IDOR across scopes — a version id from another document is rejected (16)', function () {
    [$s, $docA] = uploadedBuyerDoc();
    $bookingDoc = app(UploadDocumentAction::class)->handle($s['booking'], docType('BOOKING_FORM'), fakeDocument('bf.pdf'), $s['actor'])->fresh('currentVersion');

    // right document, wrong version → 404
    $this->actingAs(registryOfficer())
        ->get(route('documents.download', ['document' => $docA->id, 'version' => $bookingDoc->currentVersion->id]))
        ->assertNotFound();
});

it('scopes a Livewire document action to its own documentable (guessed id fails)', function () {
    [$s, $docA] = uploadedBuyerDoc();
    $otherBuyer = Buyer::factory()->create(['status' => 'active']);

    expect(fn () => Livewire::actingAs(registryOfficer())
        ->test(BuyerDocuments::class, ['buyer' => $otherBuyer])
        ->call('verify', $docA->id))
        ->toThrow(ModelNotFoundException::class);
});

it('blocks the booking document screen without bookings.view (tenant isolation)', function () {
    $s = confirmedBookingScenario();

    $noBooking = makeUser(permissions: ['documents.view']);
    $this->actingAs($noBooking)->get(route('documents.booking', $s['booking']))->assertForbidden();
});
