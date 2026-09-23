<?php

declare(strict_types=1);

use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\Masters\AreaUnit;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\BookingWitness;
use App\Models\Masters\PlotDimension;
use App\Services\Payments\PaymentLedger;
use App\Support\Branding;
use App\Support\Documents\PlotKycReceiptPdfData;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @see plotKycData() in PlotKycReceiptDataTest.php — same fresh-Branding convention. */
function renderPlotKycHtml(Booking $booking): string
{
    return view('plot-kyc-receipt.pdf', [
        'booking' => $booking,
        'brand' => Branding::fromConfig(),
        'data' => (new PlotKycReceiptPdfData(app(PaymentLedger::class), Branding::fromConfig()))->build($booking),
    ])->render();
}

it('renders as a real PDF, always fitting on one A4 page (42)', function () {
    $s = confirmedBookingScenario('1000000');

    $bytes = Pdf::loadView('plot-kyc-receipt.pdf', [
        'booking' => $s['booking'],
        'brand' => Branding::fromConfig(),
        'data' => (new PlotKycReceiptPdfData(app(PaymentLedger::class), Branding::fromConfig()))->build($s['booking']),
    ])->output();

    expect(str_starts_with($bytes, '%PDF'))->toBeTrue()
        ->and(strlen($bytes))->toBeGreaterThan(1000);

    $pageCount = preg_match_all('#/Type\s*/Page(?!s)#', $bytes);
    expect($pageCount)->toBe(1);
});

it('renders REGISTRY KYC / PART-1 with real plot/village/chauhaddi/seller/buyer/witness/payment data — no hardcoded figures (43)', function () {
    config([
        'branding.name' => 'King Builders',
        'branding.contact.director_name' => 'Rajesh Kumar',
        'branding.contact.pan_number' => 'AAACK1234B',
        'branding.contact.head_office_address' => 'Head Office, Lucknow',
        'branding.contact.phone' => '9998887770',
    ]);

    $dimension = PlotDimension::factory()->create(['width' => 30, 'length' => 45]);
    $s = confirmedBookingScenario('900000');
    $s['booking']->plot->update([
        'plot_number' => '221', 'area' => '1350.50', 'area_unit' => AreaUnit::SquareFeet->value,
        'plot_dimension_id' => $dimension->id,
        'village_name' => 'Rampur Kalan', 'gata_number' => 'GT-991',
        'boundary_east' => 'Plot 222', 'boundary_west' => 'Main Road', 'boundary_north' => 'Plot 220', 'boundary_south' => 'Drain',
    ]);
    $s['booking']->update(['vikray_muly_amount' => '850000', 'plc_amount' => '25000']);

    $s['buyer']->update(['address' => '9 Civil Lines', 'pan_number' => 'BXPPK1234C', 'phone' => '9123456780']);

    BookingWitness::factory()->create(['booking_id' => $s['booking']->id, 'witness_number' => 1, 'name' => 'Suresh Yadav', 'mobile' => '9000000001']);
    BookingWitness::factory()->create(['booking_id' => $s['booking']->id, 'witness_number' => 2, 'name' => 'Mahesh Gupta', 'mobile' => '9000000002']);

    $payment = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '300000', 'reference_number' => 'PAY-REF-9',
    ], $s['actor']);
    app(VerifyPaymentAction::class)->handle($payment, PaymentStatus::Success, $s['actor']);

    $html = renderPlotKycHtml($s['booking']->fresh());

    expect($html)
        ->toContain('REGISTRY KYC')
        ->toContain('PART-1')
        // Village / Gata / Vikray Muly
        ->toContain('Rampur Kalan')
        ->toContain('GT-991')
        ->toContain(number_format(850000, 2))
        // Plot details
        ->toContain('221')
        ->toContain('1,350.5')
        ->toContain($s['booking']->project->name)
        ->toContain('30')
        ->toContain('45')
        // Chauhaddi
        ->toContain('Plot 222')
        ->toContain('Main Road')
        ->toContain('Plot 220')
        ->toContain('Drain')
        // Seller / company
        ->toContain('King Builders')
        ->toContain('Rajesh Kumar')
        ->toContain('Head Office, Lucknow')
        ->toContain('AAACK1234B')
        ->toContain('9998887770')
        // Buyer (full, unmasked PAN — this document's whole purpose is registry KYC)
        ->toContain($s['buyer']->fullName())
        ->toContain('9 Civil Lines')
        ->toContain('BXPPK1234C')
        ->toContain('9123456780')
        // Witnesses
        ->toContain('Suresh Yadav')
        ->toContain('9000000001')
        ->toContain('Mahesh Gupta')
        ->toContain('9000000002')
        // Payment details
        ->toContain('PAY-REF-9')
        ->toContain(number_format(300000, 2))
        // Financial summary
        ->toContain('Total Plot Amount (incl. PLC)')
        ->toContain(number_format(900000, 2)) // Total Plot Amount = final_amount
        ->toContain(number_format(600000, 2)) // Balance = 900000 - 300000
        ->toContain(number_format(25000, 2))  // Total PLC
        ->toContain('Not separately tracked')
        // No reintroduced Payment Plan / Allocation / Collections concepts
        ->not->toContain('Payment Plan')
        ->not->toContain('Installment')
        ->not->toContain('Unallocated');
});

it('prints blank/dash placeholders when seller PAN, witnesses and land records are not set — never blocks rendering (44)', function () {
    config([
        'branding.contact.director_name' => null,
        'branding.contact.pan_number' => null,
    ]);

    $s = confirmedBookingScenario();

    $html = renderPlotKycHtml($s['booking']);

    expect($html)->toContain('REGISTRY KYC');
});

it('the header contains only REGISTRY KYC / PART-1 / Booking number / Date, center aligned (1)', function () {
    $s = confirmedBookingScenario();

    $html = renderPlotKycHtml($s['booking']->fresh());

    expect($html)
        ->toContain('class="header-center"')
        ->toContain('REGISTRY KYC')
        ->toContain('PART-1')
        ->toContain('Booking: '.$s['booking']->booking_number)
        ->toContain('Date: '.now()->format('d/m/Y'));
});

it('never renders a logo image or the old right-aligned company-name header block (2)', function () {
    config(['branding.name' => 'King Builders', 'branding.logo_path' => 'branding/logo.png']);
    $s = confirmedBookingScenario();

    $html = renderPlotKycHtml($s['booking']->fresh());

    expect($html)
        ->not->toContain('<img')
        ->not->toContain('class="logo"')
        ->not->toContain('class="company-name"')
        ->not->toContain('KING BUILDERS'); // the old strtoupper() header text is gone
});

it('renders a compass in the Plot Details area, without disturbing the Plot Details table (17)', function () {
    $s = confirmedBookingScenario();

    $html = renderPlotKycHtml($s['booking']->fresh());

    expect($html)
        ->toContain('class="compass-col"')
        ->toContain('class="compass"')
        ->toContain('>N<')
        ->toContain('>E<')
        ->toContain('>S<')
        ->toContain('>W<')
        ->toContain('class="plot-details-col"')
        ->toContain('Plot No.');
});

it('never renders signature lines, or a seller/company footer (19, 20)', function () {
    config(['branding.contact.head_office_address' => 'Head Office, Lucknow']);
    $s = confirmedBookingScenario();

    $html = renderPlotKycHtml($s['booking']->fresh());

    expect($html)
        ->not->toContain('sign-block')
        ->not->toContain('signatures')
        ->not->toContain('Witness 1 &amp; 2')
        ->not->toContain('class="footer"')
        ->not->toContain('system-generated Plot KYC')
        ->not->toContain('Head Office:-');
});
