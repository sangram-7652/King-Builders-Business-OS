<?php

declare(strict_types=1);

use App\Livewire\Bookings\BookingDocuments;
use App\Models\Document;
use App\Services\Payments\PaymentLedger;
use App\Support\Branding;
use App\Support\Documents\PlotKycReceiptPdfData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    seedDocumentMasters();
    Storage::fake('documents');
});

/*
| 1-2. Prefill vs. blank editable input
*/

it('prefills the Generate form from existing Plot land-record values (1)', function () {
    $s = confirmedBookingScenario();
    $s['booking']->plot->update([
        'village_name' => 'Rampur Kalan', 'gata_number' => 'GT-991',
        'boundary_east' => 'Plot 222', 'boundary_west' => 'Main Road',
        'boundary_north' => 'Plot 220', 'boundary_south' => 'Drain',
    ]);

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']->fresh()])
        ->call('openPlotKyc')
        ->assertSet('villageName', 'Rampur Kalan')
        ->assertSet('gataNumber', 'GT-991')
        ->assertSet('boundaryEast', 'Plot 222')
        ->assertSet('boundaryWest', 'Main Road')
        ->assertSet('boundaryNorth', 'Plot 220')
        ->assertSet('boundarySouth', 'Drain');
});

it('shows blank editable inputs when the Plot has no land-record values yet (2)', function () {
    $s = confirmedBookingScenario();
    // confirmedBookingScenario's plot has no land records set.

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->assertSet('villageName', '')
        ->assertSet('gataNumber', '')
        ->assertSet('boundaryEast', '')
        ->assertSet('boundaryWest', '')
        ->assertSet('boundaryNorth', '')
        ->assertSet('boundarySouth', '')
        ->assertOk()
        ->assertSee('Land records')
        ->assertSee('Plot Chauhaddi');
});

/*
| 3-4. Operator can enter the values during generation
*/

it('lets the operator enter Village Name and Gata Number during generation (3)', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->set('villageName', 'Naya Gaon')
        ->set('gataNumber', 'GT-55')
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    expect($s['booking']->plot->fresh()->village_name)->toBe('Naya Gaon')
        ->and($s['booking']->plot->fresh()->gata_number)->toBe('GT-55');
});

it('lets the operator enter all four Chauhaddi values during generation (4)', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->set('boundaryEast', 'Plot 12')
        ->set('boundaryWest', 'Canal Road')
        ->set('boundaryNorth', 'Plot 10')
        ->set('boundarySouth', 'Open Land')
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    $plot = $s['booking']->plot->fresh();
    expect($plot->boundary_east)->toBe('Plot 12')
        ->and($plot->boundary_west)->toBe('Canal Road')
        ->and($plot->boundary_north)->toBe('Plot 10')
        ->and($plot->boundary_south)->toBe('Open Land');
});

/*
| 5. Persistence to the Plot record
*/

it('persists entered land-record values to the Plot, not a second table (5)', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->set('villageName', 'Rampur')
        ->set('gataNumber', 'GT-1')
        ->set('boundaryEast', 'A')
        ->set('boundaryWest', 'B')
        ->set('boundaryNorth', 'C')
        ->set('boundarySouth', 'D')
        ->call('generatePlotKycReceipt');

    $plot = $s['booking']->plot->fresh();
    expect($plot->village_name)->toBe('Rampur')
        ->and($plot->gata_number)->toBe('GT-1')
        ->and($plot->boundary_east)->toBe('A')
        ->and($plot->boundary_west)->toBe('B')
        ->and($plot->boundary_north)->toBe('C')
        ->and($plot->boundary_south)->toBe('D');

    // No second land-record table exists.
    expect(Schema::hasTable('plot_land_records'))->toBeFalse();
});

it('leaves an already-present land-record value untouched when the operator does not edit it', function () {
    $s = confirmedBookingScenario();
    $s['booking']->plot->update(['village_name' => 'Original Village', 'gata_number' => 'GT-ORIG']);

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']->fresh()])
        ->call('openPlotKyc') // prefills villageName = 'Original Village'
        ->set('boundaryEast', 'New East') // operator only edits a different field
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    $plot = $s['booking']->plot->fresh();
    expect($plot->village_name)->toBe('Original Village')
        ->and($plot->gata_number)->toBe('GT-ORIG')
        ->and($plot->boundary_east)->toBe('New East');
});

/*
| 6. Generated PDF contains the entered values
*/

it('the generated PDF contains the land-record values entered in the form (6)', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->set('villageName', 'Chandpur')
        ->set('gataNumber', 'GT-777')
        ->set('boundaryEast', 'East Field')
        ->set('boundaryWest', 'West Field')
        ->set('boundaryNorth', 'North Field')
        ->set('boundarySouth', 'South Field')
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    $document = $s['booking']->fresh()->documents()
        ->whereHas('documentType', fn ($q) => $q->where('code', 'PLOT_KYC_RECEIPT'))
        ->firstOrFail()
        ->load('currentVersion');

    $bytes = Storage::disk('documents')->get($document->currentVersion->path);
    expect(str_starts_with($bytes, '%PDF'))->toBeTrue();

    $html = view('plot-kyc-receipt.pdf', [
        'booking' => $s['booking']->fresh(),
        'brand' => Branding::fromConfig(),
        'data' => (new PlotKycReceiptPdfData(
            app(PaymentLedger::class), Branding::fromConfig()
        ))->build($s['booking']->fresh()),
    ])->render();

    expect($html)
        ->toContain('Chandpur')
        ->toContain('GT-777')
        ->toContain('East Field')
        ->toContain('West Field')
        ->toContain('North Field')
        ->toContain('South Field');
});

/*
| 7. Next Regenerate pre-fills the saved values
*/

it('the next Regenerate automatically prefills the values saved by the previous Generate (7)', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->set('villageName', 'Saved Village')
        ->set('gataNumber', 'GT-999')
        ->set('boundaryEast', 'Saved East')
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']->fresh()])
        ->call('openPlotKyc')
        ->assertSet('villageName', 'Saved Village')
        ->assertSet('gataNumber', 'GT-999')
        ->assertSet('boundaryEast', 'Saved East');
});

/*
| 8-9. Vikray Muly / Witness behaviour unchanged
*/

it('Vikray Muly continues to prefill and persist exactly as before (8)', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->set('vikrayMulyAmount', '725000')
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    expect($s['booking']->fresh()->vikray_muly_amount)->toBe('725000.00');

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']->fresh()])
        ->call('openPlotKyc')
        ->assertSet('vikrayMulyAmount', '725000.00');
});

it('Witness 1/2 continue to prefill and persist exactly as before (9)', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->set('witness1Name', 'Suresh Yadav')
        ->set('witness1Mobile', '9000000001')
        ->set('witness2Name', 'Mahesh Gupta')
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    $fresh = $s['booking']->fresh('witnesses');
    expect($fresh->witnesses)->toHaveCount(2);

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']->fresh()])
        ->call('openPlotKyc')
        ->assertSet('witness1Name', 'Suresh Yadav')
        ->assertSet('witness1Mobile', '9000000001')
        ->assertSet('witness2Name', 'Mahesh Gupta');
});

/*
| Land records remain optional — never block generation
*/

it('generating with land records left blank still succeeds — they remain optional', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    expect(Document::query()->where('documentable_id', $s['booking']->id)->exists())->toBeTrue();
});
