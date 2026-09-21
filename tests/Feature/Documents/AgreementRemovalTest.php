<?php

declare(strict_types=1);

use App\Actions\Documents\UploadDocumentAction;
use App\Livewire\Bookings\BookingDocuments;
use App\Livewire\Portal\Bookings\Show;
use App\Models\Agreement;
use App\Models\Document;
use App\Models\Masters\DocumentType;
use App\Support\Bookings\BookingCancellationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    seedDocumentMasters();
    Storage::fake('documents');
});

/** A booking with a historical, fully-signed agreement + its generated PDF document (as the removed workflow would have left it). */
function bookingWithHistoricalAgreement(): array
{
    $s = confirmedBookingScenario();
    $actor = registryOfficer();

    $document = app(UploadDocumentAction::class)->handle($s['booking'], docType('BOOKING_AGREEMENT'), fakeDocument('agreement-v1.pdf'), $actor);

    $agreement = Agreement::factory()->signed()->create([
        'booking_id' => $s['booking']->id,
        'document_id' => $document->id,
    ]);

    return [...$s, 'agreement' => $agreement, 'document' => $document->fresh('currentVersion')];
}

/*
| 9. Historical Agreement data is preserved — not deleted, still reachable.
*/

it('preserves the historical Agreement row, its document row, and its stored file untouched (9)', function () {
    $s = bookingWithHistoricalAgreement();

    // Load the Booking Documents screen — the surface being redesigned —
    // and confirm nothing about doing so touches the historical data.
    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']->fresh()])
        ->assertOk();

    expect(Agreement::find($s['agreement']->id))->not->toBeNull()
        ->and(Agreement::find($s['agreement']->id)->status->value)->toBe('signed')
        ->and(Document::find($s['document']->id))->not->toBeNull()
        ->and($s['document']->currentVersion)->not->toBeNull();

    Storage::disk('documents')->assertExists($s['document']->currentVersion->path);
});

it('a historical Agreement is still readable through the generic document/download infrastructure (9)', function () {
    $s = bookingWithHistoricalAgreement();
    $version = $s['document']->currentVersion;

    $this->actingAs(registryOfficer())
        ->get(route('documents.download', ['document' => $s['document']->id, 'version' => $version->id]))
        ->assertOk();
});

it('a historical signed agreement still blocks cancelling its booking — BookingCancellationGuard reads it directly, no UI dependency (9)', function () {
    $s = bookingWithHistoricalAgreement();

    $blockers = app(BookingCancellationGuard::class)->blockers($s['booking']->fresh());

    expect($blockers)->toContain('a signed agreement exists');
});

it('the historical agreement status still displays on the customer portal booking page (9)', function () {
    $s = bookingWithHistoricalAgreement();
    $customer = $s['booking']->bookingBuyers()->where('is_primary', true)->first()->buyer;
    $customer->forceFill([
        'status' => 'active', 'email' => fake()->unique()->safeEmail(),
        'portal_status' => 'active', 'password' => bcrypt('pw'), 'portal_activated_at' => now(),
    ])->save();

    Livewire::actingAs($customer->fresh(), 'customer')
        ->test(Show::class, ['booking' => $s['booking']->fresh()])
        ->assertOk()
        ->assertSee('Signed');
});

it('no Booking Agreement document type row was deleted from the database (9)', function () {
    expect(DocumentType::query()->where('code', 'BOOKING_AGREEMENT')->exists())->toBeTrue();
});
