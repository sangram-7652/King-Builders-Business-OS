<?php

declare(strict_types=1);

use App\Livewire\Bookings\BookingDocuments;
use App\Models\Document;
use App\Services\Payments\PaymentLedger;
use App\Support\Branding;
use App\Support\BrandingConfigWriter;
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

/**
 * Binds BrandingConfigWriter to a throwaway temp file so a test that changes
 * Director Name / PAN NEVER touches the project's real .env. Returns the
 * temp path for assertions against the persisted content.
 */
function fakeBrandingEnvFile(): string
{
    $path = tempnam(sys_get_temp_dir(), 'kyc_env_');
    file_put_contents($path, "APP_NAME=Testing\n");
    app()->instance(BrandingConfigWriter::class, new BrandingConfigWriter($path));

    return $path;
}

/*
| 1. Existing config values are prefilled
*/

it('prefills the Generate form from existing Company Name / Director Name / Address / PAN / Mobile config values (1, 8)', function () {
    config([
        'branding.name' => 'King Builders Pvt Ltd',
        'branding.contact.director_name' => 'Rajesh Kumar',
        'branding.contact.head_office_address' => '1 Head Office Road',
        'branding.contact.pan_number' => 'AAACK1234B',
        'branding.contact.phone' => '9998887770',
    ]);
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->assertSet('companyName', 'King Builders Pvt Ltd')
        ->assertSet('directorName', 'Rajesh Kumar')
        ->assertSet('companyAddress', '1 Head Office Road')
        ->assertSet('panNumber', 'AAACK1234B')
        ->assertSet('companyMobile', '9998887770');
});

it('all five Seller / Company fields are editable inputs, not read-only text (3, 4, 5, 6, 7)', function () {
    config(['branding.contact.director_name' => null, 'branding.contact.pan_number' => null]);
    $s = confirmedBookingScenario();
    fakeBrandingEnvFile();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->set('companyName', 'New Builders Pvt Ltd')
        ->set('directorName', 'Anita Sharma')
        ->set('companyAddress', 'New Head Office, Kanpur')
        ->set('panNumber', 'BXPPK9876D')
        ->set('companyMobile', '9111122223')
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    expect(config('branding.name'))->toBe('New Builders Pvt Ltd')
        ->and(config('branding.contact.director_name'))->toBe('Anita Sharma')
        ->and(config('branding.contact.head_office_address'))->toBe('New Head Office, Kanpur')
        ->and(config('branding.contact.pan_number'))->toBe('BXPPK9876D')
        ->and(config('branding.contact.phone'))->toBe('9111122223');
});

it('persists entered Company Name / Address / Mobile to the existing branding config source (.env) (9, 10)', function () {
    config(['branding.name' => null, 'branding.contact.head_office_address' => null, 'branding.contact.phone' => null]);
    $s = confirmedBookingScenario();
    $envPath = fakeBrandingEnvFile();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->assertSet('companyName', '')
        ->assertSet('companyAddress', '')
        ->assertSet('companyMobile', '')
        ->set('companyName', 'Sunrise Builders')
        ->set('companyAddress', '5 Market Road, Kanpur')
        ->set('companyMobile', '9812345670')
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    $envContents = file_get_contents($envPath);
    expect($envContents)
        ->toContain('BRAND_NAME="Sunrise Builders"')
        ->toContain('BRAND_HEAD_OFFICE_ADDRESS="5 Market Road, Kanpur"')
        ->toContain('BRAND_PHONE=9812345670');

    // Regeneration automatically prefills what was just saved.
    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']->fresh()])
        ->call('openPlotKyc')
        ->assertSet('companyName', 'Sunrise Builders')
        ->assertSet('companyAddress', '5 Market Road, Kanpur')
        ->assertSet('companyMobile', '9812345670');
});

/*
| 2-3. Missing values show blank editable inputs and can be entered
*/

it('shows blank editable Director Name / PAN inputs when config has neither, and lets the operator enter them (2, 3)', function () {
    config(['branding.contact.director_name' => null, 'branding.contact.pan_number' => null]);
    $s = confirmedBookingScenario();
    fakeBrandingEnvFile();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->assertSet('directorName', '')
        ->assertSet('panNumber', '')
        ->assertOk()
        ->assertSee('Seller / Company')
        ->set('directorName', 'Anita Sharma')
        ->set('panNumber', 'BXPPK9876D')
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    expect(config('branding.contact.director_name'))->toBe('Anita Sharma')
        ->and(config('branding.contact.pan_number'))->toBe('BXPPK9876D');
});

/*
| 4. Persistence to the existing branding config source
*/

it('persists entered Director Name / PAN to the existing branding config source (.env), not a duplicate table (4)', function () {
    config(['branding.contact.director_name' => null, 'branding.contact.pan_number' => null]);
    $s = confirmedBookingScenario();
    $envPath = fakeBrandingEnvFile();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->set('directorName', 'Vikas Mehta')
        ->set('panNumber', 'CCCPM1234E')
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    $envContents = file_get_contents($envPath);
    expect($envContents)
        ->toContain('BRAND_DIRECTOR_NAME="Vikas Mehta"')
        ->toContain('BRAND_PAN_NUMBER=CCCPM1234E')
        ->toContain('APP_NAME=Testing'); // every other line untouched

    expect(Schema::hasTable('seller_configs'))->toBeFalse()
        ->and(Schema::hasTable('company_settings'))->toBeFalse();
});

it('leaves an already-configured value untouched, and writes nothing to .env, when the operator does not edit it', function () {
    config(['branding.contact.director_name' => 'Original Director', 'branding.contact.pan_number' => 'ORIGP1234N']);
    $s = confirmedBookingScenario();
    $envPath = fakeBrandingEnvFile();
    $before = file_get_contents($envPath);

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc') // prefills directorName = 'Original Director', panNumber = 'ORIGP1234N'
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    expect(config('branding.contact.director_name'))->toBe('Original Director')
        ->and(config('branding.contact.pan_number'))->toBe('ORIGP1234N')
        ->and(file_get_contents($envPath))->toBe($before); // no write when nothing changed
});

/*
| 5. Generated PDF contains the entered values
*/

it('the generated PDF contains the entered Director Name and PAN (5)', function () {
    config(['branding.contact.director_name' => null, 'branding.contact.pan_number' => null]);
    $s = confirmedBookingScenario();
    fakeBrandingEnvFile();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->set('directorName', 'Deepak Rao')
        ->set('panNumber', 'DDDPR5678F')
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
        'data' => (new PlotKycReceiptPdfData(app(PaymentLedger::class), Branding::fromConfig()))->build($s['booking']->fresh()),
    ])->render();

    expect($html)->toContain('Deepak Rao')->toContain('DDDPR5678F');
});

/*
| 6. Regenerate prefills the saved values
*/

it('the next Regenerate automatically prefills the Director Name / PAN just saved (6)', function () {
    config(['branding.contact.director_name' => null, 'branding.contact.pan_number' => null]);
    $s = confirmedBookingScenario();
    fakeBrandingEnvFile();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->set('directorName', 'Saved Director')
        ->set('panNumber', 'SAVED1234Z')
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']->fresh()])
        ->call('openPlotKyc')
        ->assertSet('directorName', 'Saved Director')
        ->assertSet('panNumber', 'SAVED1234Z');
});

/*
| Never blocks generation, even if the .env write itself fails
*/

it('still generates the receipt, and still reflects the entered value in THIS render, even if persisting to .env fails', function () {
    config(['branding.contact.director_name' => null, 'branding.contact.pan_number' => null]);
    $s = confirmedBookingScenario();

    // Point the writer at a path that cannot possibly be written to.
    app()->instance(BrandingConfigWriter::class, new BrandingConfigWriter('/nonexistent-dir/does-not-exist.env'));

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->set('directorName', 'Best Effort Director')
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    // Generation succeeded and this render's config already reflects it,
    // even though nothing could be written to disk.
    expect(config('branding.contact.director_name'))->toBe('Best Effort Director');

    $document = $s['booking']->fresh()->documents()
        ->whereHas('documentType', fn ($q) => $q->where('code', 'PLOT_KYC_RECEIPT'))
        ->firstOrFail();
    expect($document->currentVersion)->not->toBeNull();
});

/*
| Optional — never blocks generation when left blank
*/

it('generating with Director Name and PAN left blank still succeeds — they remain optional', function () {
    config(['branding.contact.director_name' => null, 'branding.contact.pan_number' => null]);
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    expect(Document::query()->where('documentable_id', $s['booking']->id)->exists())->toBeTrue();
});
