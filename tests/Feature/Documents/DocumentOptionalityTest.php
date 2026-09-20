<?php

declare(strict_types=1);

use App\Actions\Bookings\ConfirmBookingAction;
use App\Actions\Bookings\CreateBookingAction;
use App\Actions\Bookings\SubmitBookingAction;
use App\Actions\Documents\UploadDocumentAction;
use App\Actions\Partners\CreatePartnerAction;
use App\Enums\BookingStatus;
use App\Enums\PartnerType;
use App\Livewire\Bookings\BookingDocuments;
use App\Livewire\Buyers\BuyerDocuments;
use App\Models\DocumentRequirement;
use App\Models\Masters\DocumentType;
use App\Services\Possession\PossessionEligibilityService;
use App\Services\Registry\RegistryEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    seedDocumentMasters();
    Storage::fake('documents');
});

it('marks no document type as required, CRM-wide (10)', function () {
    expect(DocumentType::query()->where('default_required', true)->exists())->toBeFalse()
        ->and(DocumentRequirement::query()->where('required', true)->exists())->toBeFalse();

    foreach (DocumentType::query()->pluck('code') as $code) {
        expect(docType($code)->default_required)->toBeFalse();
    }
});

it('uploads a document through the booking Livewire screen (3)', function () {
    $s = confirmedBookingScenario();

    Livewire::actingAs(registryOfficer())
        ->test(BookingDocuments::class, ['booking' => $s['booking']])
        ->set('files.'.docType('BOOKING_FORM')->id, fakeDocument('booking-form.pdf'))
        ->assertHasNoErrors();

    $doc = $s['booking']->documents()->firstOrFail();
    expect($doc->document_type_id)->toBe(docType('BOOKING_FORM')->id)
        ->and($doc->currentVersion)->not->toBeNull();
    Storage::disk('documents')->assertExists($doc->currentVersion->path);
});

it('does not block a buyer/booking with zero documents from confirming a booking (11, 13)', function () {
    $s = bookingScenario();

    $booking = app(CreateBookingAction::class)->handle(bookingPayload($s), $s['actor']);
    app(SubmitBookingAction::class)->handle($booking, $s['actor']);
    $confirmed = app(ConfirmBookingAction::class)->handle($booking->fresh(), $s['actor']);

    expect($confirmed->status)->toBe(BookingStatus::Confirmed)
        ->and($s['buyerA']->documents()->count())->toBe(0)
        ->and($booking->documents()->count())->toBe(0);
});

it('does not block partner creation with zero KYC documents (12)', function () {
    $actor = registryOfficer();

    $partner = app(CreatePartnerAction::class)->handle([
        'type' => PartnerType::Individual->value,
        'name' => 'Test Promoter',
        'company_name' => null,
        'contact_person' => null,
        'phone' => '9876543210',
        'alternate_phone' => null,
        'email' => 'promoter@example.test',
        'address' => null,
        'state_id' => null,
        'city_id' => null,
        'pincode' => null,
        'pan_number' => 'ABCDE1234F',
        'rera_number' => null,
        'bank_account_name' => null,
        'bank_account_number' => null,
        'bank_ifsc' => null,
        'bank_name' => null,
        'notes' => null,
        'commission_percentage' => '2.5',
    ], $actor);

    expect($partner->exists)->toBeTrue()
        ->and($partner->documents()->count())->toBe(0);
});

it('is registry-eligible with zero buyer/booking documents once other prerequisites are met (11, 13)', function () {
    $s = registryReadyScenario();
    $s['buyer']->documents()->delete();
    $s['booking']->documents()->delete();

    $result = app(RegistryEligibilityService::class)->evaluate($s['booking']->fresh());

    expect($result->eligible)->toBeTrue()
        ->and($result->reasons())->toBe([]);
});

it('is possession-eligible with zero documents once other prerequisites are met (13)', function () {
    $s = possessionReadyScenario();
    $s['buyer']->documents()->delete();
    $s['booking']->documents()->delete();

    $result = app(PossessionEligibilityService::class)->evaluate($s['booking']->fresh());

    expect($result->eligible)->toBeTrue()
        ->and($result->reasons())->toBe([]);
});

it('still verifies and rejects documents normally, even though none are required (14, 15)', function () {
    $s = confirmedBookingScenario();
    $actor = registryOfficer();

    $doc = app(UploadDocumentAction::class)->handle($s['buyer'], docType('AADHAAR'), fakeDocument(), $actor);

    Livewire::actingAs($actor)
        ->test(BuyerDocuments::class, ['buyer' => $s['buyer']])
        ->call('verify', $doc->id)
        ->assertHasNoErrors();

    expect($doc->fresh()->status->value)->toBe('verified');

    $doc2 = app(UploadDocumentAction::class)->handle($s['buyer'], docType('PAN'), fakeDocument('pan.pdf'), $actor);

    Livewire::actingAs($actor)
        ->test(BuyerDocuments::class, ['buyer' => $s['buyer']])
        ->call('openReject', $doc2->id)
        ->set('rejectReason', 'Illegible scan')
        ->call('reject')
        ->assertHasNoErrors();

    expect($doc2->fresh()->status->value)->toBe('rejected');
});

it('leaves the Transfer ownership-move document gate untouched by the global optionality change (16)', function () {
    // Transfer's document requirement is driven entirely by config('transfer.required_document_codes')
    // and TransferEligibilityService's own verified-count check — independent of
    // DocumentType.default_required / DocumentRequirement.required, which this task set to false.
    expect(config('transfer.required_document_codes'))
        ->toBe(['TRANSFER_APPLICATION', 'TRANSFER_CONSENT', 'TRANSFER_ID_PROOF']);

    foreach (config('transfer.required_document_codes') as $code) {
        expect(docType($code)->default_required)->toBeFalse();
    }
});
