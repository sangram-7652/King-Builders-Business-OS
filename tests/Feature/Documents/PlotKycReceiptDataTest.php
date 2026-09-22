<?php

declare(strict_types=1);

use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\ReversePaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\BookingWitness;
use App\Models\Masters\PlotDimension;
use App\Services\Payments\PaymentLedger;
use App\Support\Branding;
use App\Support\Documents\PlotKycReceiptPdfData;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'branding.name' => 'King Builders',
        'branding.contact.director_name' => 'Rajesh Kumar',
        'branding.contact.pan_number' => 'AAACK1234B',
        'branding.contact.head_office_address' => '123 Head Office Road, Lucknow',
        'branding.contact.phone' => '9998887770',
    ]);
});

/**
 * Builds fresh from `Branding::fromConfig()` (never the cached app singleton)
 * so a `config()->set(...)` in a test always takes effect — same convention
 * ReceiptTest uses for the same reason.
 */
function plotKycData(Booking $booking): array
{
    return (new PlotKycReceiptPdfData(app(PaymentLedger::class), Branding::fromConfig()))->build($booking);
}

it('maps village, gata and vikray muly from plot and booking (33)', function () {
    $s = confirmedBookingScenario();
    $s['booking']->plot->update(['village_name' => 'Rampur', 'gata_number' => 'GT-882']);
    $s['booking']->update(['vikray_muly_amount' => '650000']);

    $data = plotKycData($s['booking']->fresh());

    expect($data['village']['villageName'])->toBe('Rampur')
        ->and($data['village']['gataNumber'])->toBe('GT-882')
        ->and($data['village']['vikrayMulyAmount'])->toBe('650000.00');
});

it('maps plot no., area, front/depth (from the plot dimension) and site name (34)', function () {
    $dimension = PlotDimension::factory()->create(['width' => 30, 'length' => 45]);
    $s = confirmedBookingScenario();
    $s['booking']->plot->update(['plot_dimension_id' => $dimension->id, 'plot_number' => '77', 'area' => '1200']);

    $data = plotKycData($s['booking']->fresh());

    expect($data['plot']['plotNumber'])->toBe('77')
        ->and($data['plot']['area'])->toContain('1,200')
        ->and($data['plot']['front'])->toBe('30')
        ->and($data['plot']['depth'])->toBe('45')
        ->and($data['plot']['siteName'])->toBe($s['booking']->project->name);
});

it('maps the four plot boundaries (chauhaddi) (35)', function () {
    $s = confirmedBookingScenario();
    $s['booking']->plot->update([
        'boundary_east' => 'Plot 78', 'boundary_west' => 'Road',
        'boundary_north' => 'Plot 76', 'boundary_south' => 'Nala',
    ]);

    $data = plotKycData($s['booking']->fresh());

    expect($data['chauhaddi'])->toBe([
        'east' => 'Plot 78', 'west' => 'Road', 'north' => 'Plot 76', 'south' => 'Nala',
    ]);
});

it('maps seller/company details from Branding config, never hardcoded (36)', function () {
    $s = confirmedBookingScenario();

    $data = plotKycData($s['booking']);

    expect($data['seller'])->toBe([
        'companyName' => 'King Builders',
        'directorName' => 'Rajesh Kumar',
        'address' => '123 Head Office Road, Lucknow',
        'pan' => 'AAACK1234B',
        'mobile' => '9998887770',
    ]);
});

it('maps buyer name, address, PAN (unmasked) and mobile (37)', function () {
    $s = confirmedBookingScenario();
    $s['buyer']->update(['address' => '45 MG Road', 'pan_number' => 'BXPPK1234C', 'phone' => '9123456780']);

    $data = plotKycData($s['booking']->fresh());

    expect($data['buyers'])->toHaveCount(1)
        ->and($data['buyers'][0]['name'])->toBe($s['buyer']->fullName())
        ->and($data['buyers'][0]['address'])->toBe('45 MG Road')
        ->and($data['buyers'][0]['pan'])->toBe('BXPPK1234C')
        ->and($data['buyers'][0]['mobile'])->toBe('9123456780')
        ->and($data['buyers'][0]['isPrimary'])->toBeTrue();
});

it('shows the registry buyer override for the primary buyer, per field, when set (11)', function () {
    $s = confirmedBookingScenario();
    $s['buyer']->update(['address' => '45 MG Road', 'pan_number' => 'BXPPK1234C', 'phone' => '9123456780']);
    $s['booking']->update([
        'registry_buyer_name' => 'Priya Singh',
        'registry_buyer_mobile' => '9988776655',
        'registry_buyer_address' => null, // left unset — falls back to the buyer's own address
        'registry_buyer_pan' => 'ZZZZZ9999Z',
    ]);

    $data = plotKycData($s['booking']->fresh());

    expect($data['buyers'])->toHaveCount(1)
        ->and($data['buyers'][0]['name'])->toBe('Priya Singh')
        ->and($data['buyers'][0]['mobile'])->toBe('9988776655')
        ->and($data['buyers'][0]['pan'])->toBe('ZZZZZ9999Z')
        // registry_buyer_address was left null — falls back to the buyer's real address
        ->and($data['buyers'][0]['address'])->toBe('45 MG Road');
});

it('the registry buyer override never touches booking_buyers or the Buyer master (12)', function () {
    $s = confirmedBookingScenario();
    $originalName = $s['buyer']->fullName();

    $s['booking']->update(['registry_buyer_name' => 'Priya Singh']);

    expect($s['buyer']->fresh()->fullName())->toBe($originalName)
        ->and($s['booking']->fresh()->bookingBuyers()->where('buyer_id', $s['buyer']->id)->exists())->toBeTrue();
});

it('with no registry buyer override set, the buyer section shows the actual booking buyer exactly as before', function () {
    $s = confirmedBookingScenario();
    $s['buyer']->update(['address' => '45 MG Road', 'pan_number' => 'BXPPK1234C', 'phone' => '9123456780']);

    $data = plotKycData($s['booking']->fresh());

    expect($data['buyers'][0]['name'])->toBe($s['buyer']->fullName())
        ->and($data['buyers'][0]['address'])->toBe('45 MG Road')
        ->and($data['buyers'][0]['pan'])->toBe('BXPPK1234C')
        ->and($data['buyers'][0]['mobile'])->toBe('9123456780');
});

it('always returns exactly two witness slots, blank when not recorded, filled when present (38)', function () {
    $s = confirmedBookingScenario();

    $blank = plotKycData($s['booking']);
    expect($blank['witnesses'])->toHaveCount(2)
        ->and($blank['witnesses'][0]['name'])->toBeNull()
        ->and($blank['witnesses'][1]['name'])->toBeNull();

    BookingWitness::factory()->create(['booking_id' => $s['booking']->id, 'witness_number' => 1, 'name' => 'Suresh Yadav']);
    BookingWitness::factory()->create(['booking_id' => $s['booking']->id, 'witness_number' => 2, 'name' => 'Mahesh Gupta']);

    $filled = plotKycData($s['booking']->fresh());
    expect($filled['witnesses'][0]['name'])->toBe('Suresh Yadav')
        ->and($filled['witnesses'][1]['name'])->toBe('Mahesh Gupta');
});

it('includes only SUCCESS payments — failed and reversed are excluded (39)', function () {
    $s = confirmedBookingScenario('1000000');

    $success = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '200000', 'reference_number' => 'OK-1',
    ], $s['actor']);
    app(VerifyPaymentAction::class)->handle($success, PaymentStatus::Success, $s['actor']);

    $failed = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '50000', 'reference_number' => 'FAIL-1',
    ], $s['actor']);
    app(VerifyPaymentAction::class)->handle($failed, PaymentStatus::Failed, $s['actor']);

    $toReverse = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '75000', 'reference_number' => 'REV-1',
    ], $s['actor']);
    $verifiedToReverse = app(VerifyPaymentAction::class)->handle($toReverse, PaymentStatus::Success, $s['actor']);
    app(ReversePaymentAction::class)->handle($verifiedToReverse, 'test reversal', $s['actor'], systemInitiated: true);

    $data = plotKycData($s['booking']->fresh());

    expect($data['payments'])->toHaveCount(1)
        ->and($data['payments'][0]['referenceNumber'])->toBe('OK-1')
        ->and($data['payments'][0]['amount'])->toBe('200000.00')
        ->and($data['financial']['paidPlotAmount'])->toBe('200000.00')
        ->and($data['financial']['balanceAmount'])->toBe('800000.00');
});

it('financial summary reuses PaymentLedger for total/paid/balance and never derives Paid/Balance PLC (40)', function () {
    $s = confirmedBookingScenario('1000000');
    $s['booking']->update(['plc_amount' => '50000']);

    $data = plotKycData($s['booking']->fresh());

    expect($data['financial']['totalPlotAmount'])->toBe('1000000.00')
        ->and($data['financial']['paidPlotAmount'])->toBe('0.00')
        ->and($data['financial']['balanceAmount'])->toBe('1000000.00')
        ->and($data['financial']['totalPlcAmount'])->toBe('50000.00')
        ->and($data['financial']['paidPlcAmount'])->toBeNull()
        ->and($data['financial']['balancePlcAmount'])->toBeNull();
});

it('document title is the fixed "REGISTRY KYC / PART-1" header (41)', function () {
    $s = confirmedBookingScenario();

    $data = plotKycData($s['booking']);

    expect($data['documentTitle'])->toBe('REGISTRY KYC')
        ->and($data['documentSubtitle'])->toBe('PART-1');
});
