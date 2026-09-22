<?php

declare(strict_types=1);

use App\Livewire\Bookings\BookingDocuments;
use App\Models\Document;
use App\Services\Payments\PaymentLedger;
use App\Support\Branding;
use App\Support\Documents\PlotKycReceiptPdfData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    seedDocumentMasters();
    Storage::fake('documents');
});

it('prefills the Registry Buyer fields from the booking primary buyer when none is saved yet', function () {
    $s = confirmedBookingScenario();
    $s['buyer']->update(['address' => '45 MG Road', 'pan_number' => 'BXPPK1234C', 'phone' => '9123456780']);

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->assertSet('registryBuyerName', $s['buyer']->fullName())
        ->assertSet('registryBuyerMobile', '9123456780')
        ->assertSet('registryBuyerAddress', '45 MG Road')
        ->assertSet('registryBuyerPan', 'BXPPK1234C');
});

it('lets the operator set a Registry Buyer name that differs from the booking buyer (11)', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->set('registryBuyerName', 'Priya Singh')
        ->set('registryBuyerMobile', '9988776655')
        ->set('registryBuyerAddress', '9 Civil Lines')
        ->set('registryBuyerPan', 'ZZZZZ9999Z')
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    $fresh = $s['booking']->fresh();
    expect($fresh->registry_buyer_name)->toBe('Priya Singh')
        ->and($fresh->registry_buyer_mobile)->toBe('9988776655')
        ->and($fresh->registry_buyer_address)->toBe('9 Civil Lines')
        ->and($fresh->registry_buyer_pan)->toBe('ZZZZZ9999Z');
});

it('editing the Registry Buyer never modifies the actual booking buyer or booking_buyers (12)', function () {
    $s = confirmedBookingScenario();
    $originalName = $s['buyer']->fullName();
    $originalPhone = $s['buyer']->phone;

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->set('registryBuyerName', 'Priya Singh')
        ->set('registryBuyerMobile', '9988776655')
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    $buyerFresh = $s['buyer']->fresh();
    expect($buyerFresh->fullName())->toBe($originalName)
        ->and($buyerFresh->phone)->toBe($originalPhone);

    $bookingBuyer = $s['booking']->fresh()->bookingBuyers()->first();
    expect($bookingBuyer->buyer_id)->toBe($s['buyer']->id)
        ->and($bookingBuyer->is_primary)->toBeTrue();
});

it('the next Regenerate automatically prefills the Registry Buyer details just saved (13)', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->set('registryBuyerName', 'Priya Singh')
        ->set('registryBuyerMobile', '9988776655')
        ->set('registryBuyerAddress', '9 Civil Lines')
        ->set('registryBuyerPan', 'ZZZZZ9999Z')
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']->fresh()])
        ->call('openPlotKyc')
        ->assertSet('registryBuyerName', 'Priya Singh')
        ->assertSet('registryBuyerMobile', '9988776655')
        ->assertSet('registryBuyerAddress', '9 Civil Lines')
        ->assertSet('registryBuyerPan', 'ZZZZZ9999Z');
});

it('the generated PDF shows the Registry Buyer name instead of the booking buyer name', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->set('registryBuyerName', 'Priya Singh')
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    $html = view('plot-kyc-receipt.pdf', [
        'booking' => $s['booking']->fresh(),
        'brand' => Branding::fromConfig(),
        'data' => (new PlotKycReceiptPdfData(
            app(PaymentLedger::class),
            Branding::fromConfig()
        ))->build($s['booking']->fresh()),
    ])->render();

    expect($html)->toContain('Priya Singh')
        ->not->toContain($s['buyer']->fullName());
});

it('rejects an invalid Registry Buyer PAN and never generates a document', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->set('registryBuyerPan', 'not-a-pan')
        ->call('generatePlotKycReceipt')
        ->assertHasErrors(['registryBuyerPan']);

    expect(Document::query()->where('documentable_id', $s['booking']->id)->exists())->toBeFalse();
});

it('generating with the Registry Buyer fields blanked still succeeds', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->call('openPlotKyc')
        ->set('registryBuyerName', '')
        ->set('registryBuyerMobile', '')
        ->set('registryBuyerAddress', '')
        ->set('registryBuyerPan', '')
        ->call('generatePlotKycReceipt')
        ->assertHasNoErrors();

    expect($s['booking']->fresh()->registry_buyer_name)->toBeNull();
});
