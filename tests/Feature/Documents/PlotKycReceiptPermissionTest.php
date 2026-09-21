<?php

declare(strict_types=1);

use App\Actions\Bookings\GeneratePlotKycReceiptAction;
use App\Livewire\Bookings\BookingDocuments;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    seedDocumentMasters();
    Storage::fake('documents');
});

function generatedPlotKycDocument(): array
{
    $s = confirmedBookingScenario();
    $document = app(GeneratePlotKycReceiptAction::class)->handle($s['booking'], registryOfficer())->fresh('currentVersion');

    return [$s, $document];
}

it('a user with documents.upload + bookings.view can open the booking documents screen and generate the receipt (54)', function () {
    $s = confirmedBookingScenario();
    $user = makeUser(permissions: ['documents.view', 'documents.upload', 'documents.download', 'bookings.view']);

    Livewire::actingAs($user)
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    expect(Document::query()->where('documentable_id', $s['booking']->id)->exists())->toBeTrue();
});

it('a user without documents.upload cannot generate the receipt via the Livewire component (55)', function () {
    $s = confirmedBookingScenario();
    $noUpload = makeUser(permissions: ['documents.view', 'bookings.view']);

    // Livewire's test harness renders a denied AuthorizationException as a
    // 403 response rather than re-throwing it (it disables exception
    // handling for everything except HttpException/AuthorizationException —
    // see Livewire\Features\SupportTesting\RequestBroker), so the functional
    // assertion is that no document was ever created.
    Livewire::actingAs($noUpload)
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('generatePlotKycReceipt');

    expect(Document::query()->where('documentable_id', $s['booking']->id)->exists())->toBeFalse();
});

it('blocks the booking documents screen entirely without documents.view (56)', function () {
    $s = confirmedBookingScenario();
    $noDocs = makeUser(permissions: ['bookings.view']);

    $this->actingAs($noDocs)->get(route('documents.booking', $s['booking']))->assertForbidden();
});

it('serves the generated receipt only through the authorised documents.download route (57)', function () {
    [$s, $document] = generatedPlotKycDocument();
    $url = route('documents.download', ['document' => $document->id, 'version' => $document->currentVersion->id]);

    $this->actingAs(registryOfficer())->get($url)->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('blocks download of the generated receipt without documents.download (58)', function () {
    [$s, $document] = generatedPlotKycDocument();
    $url = route('documents.download', ['document' => $document->id, 'version' => $document->currentVersion->id]);

    $noPerm = makeUser(permissions: ['documents.view', 'bookings.view']);
    $this->actingAs($noPerm)->get($url)->assertForbidden();
});

it('prevents IDOR — a user who cannot see the booking cannot download its Plot KYC Receipt (59)', function () {
    [$s, $document] = generatedPlotKycDocument();
    $url = route('documents.download', ['document' => $document->id, 'version' => $document->currentVersion->id]);

    // holds documents.download but NOT bookings.view → cannot reach the documentable
    $outsider = makeUser(permissions: ['documents.download']);
    $this->actingAs($outsider)->get($url)->assertForbidden();
});

it('all authorisation is enforced server-side — the Livewire generate call cannot be bypassed by hiding the button (60)', function () {
    $s = confirmedBookingScenario();
    $noUpload = makeUser(permissions: ['documents.view', 'documents.download', 'bookings.view']);

    // The UI would hide the "Generate" button (no documents.upload), but the
    // server-side call must still reject it even if invoked directly.
    Livewire::actingAs($noUpload)
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('generatePlotKycReceipt');

    expect(Document::query()->where('documentable_id', $s['booking']->id)->exists())->toBeFalse();
});
