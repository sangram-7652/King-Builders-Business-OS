<?php

declare(strict_types=1);

use App\Actions\Documents\UploadDocumentAction;
use App\Livewire\Bookings\BookingDocuments;
use App\Services\Documents\DocumentChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    seedDocumentMasters();
    Storage::fake('documents');
});

it('shows Booking Form on the Booking Documents screen (1)', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->assertOk()
        ->assertSee('Booking Form');
});

it('shows Payment Documents on the Booking Documents screen (2)', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->assertOk()
        ->assertSee('Payment Documents');
});

it('shows Registry Documents on the Booking Documents screen (3)', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->assertOk()
        ->assertSee('Registry Documents');
});

it('never shows any other generic document type on the Booking Documents screen (4)', function () {
    $s = confirmedBookingScenario();

    $rendered = Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->assertOk();

    foreach ([
        'Registered Deed', 'Handover Acknowledgement', 'Possession Letter', 'NOC',
        'Possession Certificate', 'Possession Acknowledgement', 'Site Inspection Report',
        'Transfer Application', 'Transfer Consent', 'Transfer ID Proof',
    ] as $hiddenTypeName) {
        $rendered->assertDontSee($hiddenTypeName);
    }
});

it('never shows the Agreement card or any of its workflow controls (4b)', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->assertOk()
        ->assertDontSee('Agreement')
        ->assertDontSee('Create agreement')
        ->assertDontSee('Record signed')
        ->assertDontSee('Signed by')
        ->assertDontSee('Signed scan');
});

it('exposes no Agreement workflow method or property on the Livewire component (8)', function () {
    foreach ([
        'createAgreement', 'prepareAgreement', 'sendAgreement',
        'signAgreement', 'approveAgreement', 'cancelAgreement',
    ] as $method) {
        expect(method_exists(BookingDocuments::class, $method))->toBeFalse("BookingDocuments::{$method} should not exist");
    }

    foreach (['showSign', 'signedBy', 'signedFile'] as $property) {
        expect(property_exists(BookingDocuments::class, $property))->toBeFalse("BookingDocuments::\${$property} should not exist");
    }
});

it('the removed Agreement workflow action/policy/PDF-service files no longer exist in the codebase (8)', function () {
    // Checks the file directly rather than class_exists(): a failed
    // autoload attempt emits a PHP warning that this app's strict error
    // handling promotes to a fatal ErrorException, so class_exists() itself
    // isn't a safe "prove it's gone" probe here.
    foreach ([
        'app/Actions/Agreements/CreateAgreementAction.php',
        'app/Actions/Agreements/PrepareAgreementAction.php',
        'app/Actions/Agreements/TransitionAgreementAction.php',
        'app/Services/Documents/AgreementPdfService.php',
        'app/Policies/AgreementPolicy.php',
    ] as $removedFile) {
        expect(file_exists(base_path($removedFile)))->toBeFalse("{$removedFile} should have been removed");
    }
});

it('still lets Plot KYC Receipt be generated through its own dedicated card, not as a 4th generic category (4c)', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->assertOk()
        ->assertSee('Plot KYC Receipt');
});

it('the checklist service scoped to this screen counts only Booking Form, even with other booking documents on file', function () {
    $s = confirmedBookingScenario();
    $actor = registryOfficer();

    app(UploadDocumentAction::class)->handle($s['booking'], docType('REGISTERED_DEED'), fakeDocument('deed.pdf'), $actor);
    app(UploadDocumentAction::class)->handle($s['booking'], docType('NOC'), fakeDocument('noc.pdf'), $actor);

    $checklist = app(DocumentChecklistService::class)->forBooking($s['booking']->fresh(), ['BOOKING_FORM']);

    expect($checklist->items)->toHaveCount(1)
        ->and($checklist->items[0]['name'])->toBe('Booking Form');
});

it('the unfiltered checklist (used by eligibility services elsewhere) is unaffected — still sees every booking-scope type', function () {
    $s = confirmedBookingScenario();

    $checklist = app(DocumentChecklistService::class)->forBooking($s['booking']);

    $names = array_column($checklist->items, 'name');
    expect($names)->toContain('Booking Form')
        ->toContain('Registered Deed')
        ->toContain('NOC')
        ->toContain('Transfer Application');
});
