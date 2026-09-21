<?php

declare(strict_types=1);

use App\Actions\Documents\UploadDocumentAction;
use App\Enums\CustomerActivityType;
use App\Livewire\Portal\Bookings\Show;
use App\Livewire\Portal\Documents\Index;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    seedDocumentMasters();
    Storage::fake('documents');
});

function uploadedBookingDoc(Booking $booking, string $code = 'BOOKING_AGREEMENT'): Document
{
    return app(UploadDocumentAction::class)
        ->handle($booking, docType($code), fakeDocument('agreement.pdf'), User::factory()->create())
        ->fresh(['currentVersion', 'documentType']);
}

function uploadedPortalKycDoc(Buyer $buyer, string $code = 'PAN'): Document
{
    return app(UploadDocumentAction::class)
        ->handle($buyer, docType($code), fakeDocument('pan.pdf'), User::factory()->create())
        ->fresh(['currentVersion', 'documentType']);
}

/*
| Index page (portal.documents.index)
*/

it('lists the customer’s own KYC and booking documents together', function () {
    ['customer' => $customer, 'booking' => $booking] = portalBooking();
    $bookingDoc = uploadedBookingDoc($booking);
    $kycDoc = uploadedPortalKycDoc($customer);

    Livewire::actingAs($customer, 'customer')
        ->test(Index::class)
        ->assertOk()
        ->assertSee($bookingDoc->documentType->name)
        ->assertSee('Booking '.$booking->booking_number)
        ->assertSee($kycDoc->documentType->name)
        ->assertSee('My KYC');
});

it('never lists another customer’s KYC or booking documents (scoping)', function () {
    ['customer' => $a] = portalBooking();
    ['customer' => $b, 'booking' => $bBooking] = portalBooking();
    $bBookingDoc = uploadedBookingDoc($bBooking, 'REGISTRY_DOC');
    $bKycDoc = uploadedPortalKycDoc($b, 'AADHAAR');

    Livewire::actingAs($a, 'customer')
        ->test(Index::class)
        ->assertOk()
        ->assertDontSee($bBookingDoc->currentVersion->original_filename)
        ->assertDontSee('Aadhaar');
});

it('shows an "awaiting upload" state for a document with no file yet, no download link', function () {
    ['customer' => $customer, 'booking' => $booking] = portalBooking();
    // A Document row can exist without a current version (e.g. rejected then
    // never re-uploaded) — simulate it directly.
    $doc = Document::factory()->create([
        'documentable_type' => (new Booking)->getMorphClass(),
        'documentable_id' => $booking->id,
        'document_type_id' => docType('NOC')->id,
        'current_version_id' => null,
        'status' => 'pending',
    ]);

    Livewire::actingAs($customer, 'customer')
        ->test(Index::class)
        ->assertOk()
        ->assertSee('Awaiting upload')
        ->assertDontSee('Download');
});

/*
| Booking-show integration
*/

it('shows the booking’s own documents on the booking detail page', function () {
    ['customer' => $customer, 'booking' => $booking] = portalBooking();
    $document = uploadedBookingDoc($booking);

    Livewire::actingAs($customer, 'customer')
        ->test(Show::class, ['booking' => $booking])
        ->assertOk()
        ->assertSee($document->documentType->name)
        ->assertSee('agreement.pdf');
});

it('omits the Documents card entirely when the booking has no documents yet', function () {
    ['customer' => $customer, 'booking' => $booking] = portalBooking();

    Livewire::actingAs($customer, 'customer')
        ->test(Show::class, ['booking' => $booking])
        ->assertOk()
        ->assertDontSee('Documents');
});

/*
| Download — booking-scoped documents
*/

it('streams a booking document download for the owner and records a download audit event', function () {
    ['customer' => $customer, 'booking' => $booking] = portalBooking();
    $document = uploadedBookingDoc($booking);

    $this->actingAs($customer, 'customer')
        ->get(route('portal.documents.download', ['document' => $document->id, 'version' => $document->current_version_id]))
        ->assertOk();

    expect($customer->fresh()->portalActivities()
        ->where('type', CustomerActivityType::DocumentDownloaded->value)
        ->exists())->toBeTrue();
});

it('404s a booking document download for a customer who does not own the booking (IDOR)', function () {
    ['customer' => $a] = portalBooking();
    ['booking' => $bBooking] = portalBooking();
    $document = uploadedBookingDoc($bBooking);

    $this->actingAs($a, 'customer')
        ->get(route('portal.documents.download', ['document' => $document->id, 'version' => $document->current_version_id]))
        ->assertNotFound();
});

it('404s when the version id does not belong to the document (IDOR across documents)', function () {
    ['customer' => $customer, 'booking' => $booking] = portalBooking();
    $docA = uploadedBookingDoc($booking, 'BOOKING_AGREEMENT');
    $docB = uploadedBookingDoc($booking, 'REGISTRY_DOC');

    $this->actingAs($customer, 'customer')
        ->get(route('portal.documents.download', ['document' => $docA->id, 'version' => $docB->current_version_id]))
        ->assertNotFound();
});

/*
| Download — the customer's own KYC documents
*/

it('streams the customer’s own KYC document download', function () {
    ['customer' => $customer] = portalBooking();
    $document = uploadedPortalKycDoc($customer);

    $this->actingAs($customer, 'customer')
        ->get(route('portal.documents.download', ['document' => $document->id, 'version' => $document->current_version_id]))
        ->assertOk();
});

it('404s a KYC document download for a different customer (IDOR)', function () {
    ['customer' => $a] = portalBooking();
    ['customer' => $b] = portalBooking();
    $bDoc = uploadedPortalKycDoc($b);

    $this->actingAs($a, 'customer')
        ->get(route('portal.documents.download', ['document' => $bDoc->id, 'version' => $bDoc->current_version_id]))
        ->assertNotFound();
});

it('requires portal authentication for the document download', function () {
    ['booking' => $booking] = portalBooking();
    $document = uploadedBookingDoc($booking);

    $this->get(route('portal.documents.download', ['document' => $document->id, 'version' => $document->current_version_id]))
        ->assertRedirect(route('portal.login'));
});

it('requires portal authentication for the documents index', function () {
    $this->get(route('portal.documents.index'))->assertRedirect(route('portal.login'));
});
