<?php

declare(strict_types=1);

use App\Livewire\Bookings\BookingDocuments;
use App\Models\BookingWitness;
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

it('the booking documents screen shows "Generate" with no receipt yet, then "Regenerate" after one exists (61)', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->assertSee('Plot KYC Receipt')
        ->assertSee('Generate')
        ->assertDontSee('Regenerate')
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']->fresh()])
        ->assertSee('Regenerate');
});

it('opening the generate form prefills previously-saved Vikray Muly and witness details (62)', function () {
    $s = confirmedBookingScenario();
    $s['booking']->update(['vikray_muly_amount' => '500000']);
    BookingWitness::factory()->create(['booking_id' => $s['booking']->id, 'witness_number' => 1, 'name' => 'Suresh Yadav', 'mobile' => '9000000001']);

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']->fresh()])
        ->call('openPlotKyc')
        ->assertSet('vikrayMulyAmount', '500000.00')
        ->assertSet('witness1Name', 'Suresh Yadav')
        ->assertSet('witness1Mobile', '9000000001')
        ->assertSet('witness2Name', '');
});

it('generates the receipt end-to-end from the form, with witness + Vikray Muly details, and it is downloadable (63)', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->set('vikrayMulyAmount', '610000')
        ->set('witness1Name', 'Suresh Yadav')
        ->set('witness1Address', 'Village Road')
        ->set('witness1Mobile', '9000000001')
        ->set('witness2Name', 'Mahesh Gupta')
        ->set('witness2Mobile', '9000000002')
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors()
        ->assertSet('showPlotKyc', false);

    $booking = $s['booking']->fresh(['witnesses', 'documents.currentVersion']);
    expect($booking->vikray_muly_amount)->toBe('610000.00')
        ->and($booking->witnesses)->toHaveCount(2);

    $document = $booking->documents->firstWhere('documentType.code', 'PLOT_KYC_RECEIPT')
        ?? Document::query()->where('documentable_id', $booking->id)->first();

    expect($document)->not->toBeNull();

    $url = route('documents.download', ['document' => $document->id, 'version' => $document->currentVersion->id]);
    $this->actingAs(registryOfficer())->get($url)->assertOk();
});

it('rejects an invalid witness mobile number and never generates a document (64)', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->set('witness1Name', 'Suresh Yadav')
        ->set('witness1Mobile', 'not-a-number')
        ->call('generatePlotKycReceipt')
        ->assertHasErrors(['witness1Mobile']);

    expect(Document::query()->where('documentable_id', $s['booking']->id)->exists())->toBeFalse();
});

it('generating with everything blank still succeeds — witnesses and Vikray Muly are optional (65)', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    expect(Document::query()->where('documentable_id', $s['booking']->id)->exists())->toBeTrue();
});
