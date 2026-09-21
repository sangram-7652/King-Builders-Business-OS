<?php

declare(strict_types=1);

use App\Actions\Documents\AddDocumentAction;
use App\Actions\Documents\VerifyDocumentAction;
use App\Enums\DocumentActivityType;
use App\Exceptions\DomainException;
use App\Livewire\Bookings\BookingDocuments;
use App\Models\Document;
use App\Models\DocumentActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    seedDocumentMasters();
    Storage::fake('documents');
});

/*
| AddDocumentAction — the multi-file mechanics
*/

it('creates a new independent Document row for each upload of a multi type (5)', function () {
    $s = confirmedBookingScenario();
    $actor = registryOfficer();

    $a = app(AddDocumentAction::class)->handle($s['booking'], docType('PAYMENT_PROOF'), fakeDocument('payment-cheque-1.pdf'), $actor);
    $b = app(AddDocumentAction::class)->handle($s['booking'], docType('PAYMENT_PROOF'), fakeDocument('neft-receipt.pdf'), $actor);
    $c = app(AddDocumentAction::class)->handle($s['booking'], docType('PAYMENT_PROOF'), fakeDocument('payment-proof-2.jpg'), $actor);

    expect($a->id)->not->toBe($b->id)
        ->and($b->id)->not->toBe($c->id)
        ->and([$a->sequence, $b->sequence, $c->sequence])->toBe([1, 2, 3]);

    $rows = Document::query()->where('documentable_id', $s['booking']->id)
        ->whereHas('documentType', fn ($q) => $q->where('code', 'PAYMENT_PROOF'))
        ->with('currentVersion')
        ->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('currentVersion.original_filename', 'id'))->toHaveCount(3);
});

it('does not overwrite an earlier payment document when another is uploaded — the first version/file is untouched (6)', function () {
    $s = confirmedBookingScenario();
    $actor = registryOfficer();

    $first = app(AddDocumentAction::class)->handle($s['booking'], docType('PAYMENT_PROOF'), fakeDocument('payment-cheque-1.pdf'), $actor);
    $firstPath = $first->fresh('currentVersion')->currentVersion->path;

    app(AddDocumentAction::class)->handle($s['booking'], docType('PAYMENT_PROOF'), fakeDocument('neft-receipt.pdf'), $actor);

    $firstReloaded = $first->fresh('currentVersion');
    expect($firstReloaded->currentVersion->path)->toBe($firstPath)
        ->and($firstReloaded->currentVersion->original_filename)->toBe('payment-cheque-1.pdf')
        ->and($firstReloaded->versions()->count())->toBe(1);

    Storage::disk('documents')->assertExists($firstPath);
});

it('creates a new independent Document row for each upload of Registry Documents too (7)', function () {
    $s = confirmedBookingScenario();
    $actor = registryOfficer();

    $a = app(AddDocumentAction::class)->handle($s['booking'], docType('REGISTRY_DOC'), fakeDocument('sale-deed-draft.pdf'), $actor);
    $b = app(AddDocumentAction::class)->handle($s['booking'], docType('REGISTRY_DOC'), fakeDocument('stamp-duty-receipt.pdf'), $actor);

    expect($a->id)->not->toBe($b->id)
        ->and($a->sequence)->toBe(1)
        ->and($b->sequence)->toBe(2);

    expect(Document::query()->where('documentable_id', $s['booking']->id)
        ->whereHas('documentType', fn ($q) => $q->where('code', 'REGISTRY_DOC'))
        ->count())->toBe(2);
});

it('refuses to add a second document for a single-slot type — use UploadDocumentAction instead', function () {
    $s = confirmedBookingScenario();

    expect(fn () => app(AddDocumentAction::class)->handle($s['booking'], docType('BOOKING_FORM'), fakeDocument(), registryOfficer()))
        ->toThrow(DomainException::class);
});

it('records a document-uploaded timeline event per file added', function () {
    $s = confirmedBookingScenario();
    $actor = registryOfficer();

    app(AddDocumentAction::class)->handle($s['booking'], docType('PAYMENT_PROOF'), fakeDocument('a.pdf'), $actor);
    app(AddDocumentAction::class)->handle($s['booking'], docType('PAYMENT_PROOF'), fakeDocument('b.pdf'), $actor);

    expect(DocumentActivity::query()
        ->where('booking_id', $s['booking']->id)
        ->where('type', DocumentActivityType::DocumentUploaded->value)
        ->count())->toBe(2);
});

/*
| End-to-end via the Booking Documents Livewire screen
*/

it('uploads several payment documents from the screen, none replacing the others (5, 6)', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->set('newDocuments.PAYMENT_PROOF', fakeDocument('payment-cheque-1.pdf'))
        ->assertHasNoErrors();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']->fresh()])
        ->set('newDocuments.PAYMENT_PROOF', fakeDocument('neft-receipt.pdf'))
        ->assertHasNoErrors();

    $rows = Document::query()->where('documentable_id', $s['booking']->id)
        ->whereHas('documentType', fn ($q) => $q->where('code', 'PAYMENT_PROOF'))
        ->with('currentVersion')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('currentVersion.original_filename')->sort()->values()->all())
        ->toBe(['neft-receipt.pdf', 'payment-cheque-1.pdf']);
});

it('uploads several registry documents from the screen, none replacing the others (7)', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->set('newDocuments.REGISTRY_DOC', fakeDocument('draft.pdf'))
        ->assertHasNoErrors();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']->fresh()])
        ->set('newDocuments.REGISTRY_DOC', fakeDocument('stamp-receipt.pdf'))
        ->assertHasNoErrors();

    expect(Document::query()->where('documentable_id', $s['booking']->id)
        ->whereHas('documentType', fn ($q) => $q->where('code', 'REGISTRY_DOC'))
        ->count())->toBe(2);
});

it('lists every uploaded payment and registry document on the screen', function () {
    $s = confirmedBookingScenario();
    $actor = registryOfficer();

    app(AddDocumentAction::class)->handle($s['booking'], docType('PAYMENT_PROOF'), fakeDocument('payment-cheque-1.pdf'), $actor);
    app(AddDocumentAction::class)->handle($s['booking'], docType('PAYMENT_PROOF'), fakeDocument('receipt.pdf'), $actor);
    app(AddDocumentAction::class)->handle($s['booking'], docType('REGISTRY_DOC'), fakeDocument('sale-deed-draft.pdf'), $actor);

    Livewire::actingAs($actor)
        ->test(BookingDocuments::class, ['booking' => $s['booking']->fresh()])
        ->assertOk()
        ->assertSee('payment-cheque-1.pdf')
        ->assertSee('receipt.pdf')
        ->assertSee('sale-deed-draft.pdf');
});

it('requires documents.upload to add a payment/registry document', function () {
    $s = confirmedBookingScenario();
    $noUpload = makeUser(permissions: ['documents.view', 'bookings.view']);

    Livewire::actingAs($noUpload)
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->set('newDocuments.PAYMENT_PROOF', fakeDocument('a.pdf'));

    expect(Document::query()->where('documentable_id', $s['booking']->id)->exists())->toBeFalse();
});

/*
| Verification / rejection / delete / download still work on a multi document (9)
*/

it('still verifies and rejects individual payment documents normally (9)', function () {
    $s = confirmedBookingScenario();
    $actor = registryOfficer();

    $paid1 = app(AddDocumentAction::class)->handle($s['booking'], docType('PAYMENT_PROOF'), fakeDocument('a.pdf'), $actor);
    $paid2 = app(AddDocumentAction::class)->handle($s['booking'], docType('PAYMENT_PROOF'), fakeDocument('b.pdf'), $actor);

    Livewire::actingAs($actor)
        ->test(BookingDocuments::class, ['booking' => $s['booking']->fresh()])
        ->call('verify', $paid1->id)
        ->assertHasNoErrors();

    expect($paid1->fresh()->status->value)->toBe('verified')
        // the sibling document is untouched by verifying the first
        ->and($paid2->fresh()->status->value)->toBe('uploaded');

    Livewire::actingAs($actor)
        ->test(BookingDocuments::class, ['booking' => $s['booking']->fresh()])
        ->call('openReject', $paid2->id)
        ->set('rejectReason', 'Illegible scan')
        ->call('reject')
        ->assertHasNoErrors();

    expect($paid2->fresh()->status->value)->toBe('rejected')
        ->and($paid1->fresh()->status->value)->toBe('verified');
});

it('a verified payment document cannot be deleted, an unverified one can (9)', function () {
    $s = confirmedBookingScenario();
    $actor = registryOfficer();

    $verified = app(AddDocumentAction::class)->handle($s['booking'], docType('PAYMENT_PROOF'), fakeDocument('a.pdf'), $actor);
    app(VerifyDocumentAction::class)->handle($verified, $actor);
    $plain = app(AddDocumentAction::class)->handle($s['booking'], docType('PAYMENT_PROOF'), fakeDocument('b.pdf'), $actor);

    Livewire::actingAs($actor)
        ->test(BookingDocuments::class, ['booking' => $s['booking']->fresh()])
        ->call('deleteDocument', $verified->id);
    expect(Document::find($verified->id))->not->toBeNull();

    Livewire::actingAs($actor)
        ->test(BookingDocuments::class, ['booking' => $s['booking']->fresh()])
        ->call('deleteDocument', $plain->id);
    expect(Document::find($plain->id))->toBeNull();
});

/*
| Download / authorization protection is unchanged (8)
*/

it('downloads a payment document only through the existing authorised route, IDOR-protected (8)', function () {
    $s = confirmedBookingScenario();
    $doc = app(AddDocumentAction::class)->handle($s['booking'], docType('PAYMENT_PROOF'), fakeDocument('a.pdf'), registryOfficer())
        ->fresh('currentVersion');

    $this->actingAs(registryOfficer())
        ->get(route('documents.download', ['document' => $doc->id, 'version' => $doc->currentVersion->id]))
        ->assertOk();

    $noDownload = makeUser(permissions: ['documents.view', 'bookings.view']);
    $this->actingAs($noDownload)
        ->get(route('documents.download', ['document' => $doc->id, 'version' => $doc->currentVersion->id]))
        ->assertForbidden();
});

it('prevents IDOR — a user who cannot view the booking cannot download its payment document (8)', function () {
    $s = confirmedBookingScenario();
    $doc = app(AddDocumentAction::class)->handle($s['booking'], docType('PAYMENT_PROOF'), fakeDocument('a.pdf'), registryOfficer())
        ->fresh('currentVersion');

    $outsider = makeUser(permissions: ['documents.download']);
    $this->actingAs($outsider)
        ->get(route('documents.download', ['document' => $doc->id, 'version' => $doc->currentVersion->id]))
        ->assertForbidden();
});
