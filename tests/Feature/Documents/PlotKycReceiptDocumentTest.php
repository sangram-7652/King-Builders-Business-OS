<?php

declare(strict_types=1);

use App\Actions\Bookings\GeneratePlotKycReceiptAction;
use App\Enums\DocumentActivityType;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Document;
use App\Models\DocumentActivity;
use App\Models\Masters\DocumentType;
use Database\Seeders\Masters\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    seedDocumentMasters();
    Storage::fake('documents');
});

it('generates v1 as a versioned PLOT_KYC_RECEIPT document on the booking (45)', function () {
    $s = confirmedBookingScenario();

    $document = app(GeneratePlotKycReceiptAction::class)->handle($s['booking'], registryOfficer());

    expect($document->documentable_type)->toBe((new Booking)->getMorphClass())
        ->and($document->documentable_id)->toBe($s['booking']->id)
        ->and($document->documentType->code)->toBe('PLOT_KYC_RECEIPT')
        ->and($document->versions)->toHaveCount(1)
        ->and($document->currentVersion)->not->toBeNull();
});

it('regenerating appends a new version and keeps the old one — no duplicate Document row (46)', function () {
    $s = confirmedBookingScenario();

    $first = app(GeneratePlotKycReceiptAction::class)->handle($s['booking'], registryOfficer());

    // change something so the re-render is not byte-identical
    $s['booking']->plot->update(['village_name' => 'Rampur']);

    $second = app(GeneratePlotKycReceiptAction::class)->handle($s['booking']->fresh(), registryOfficer());

    expect($second->id)->toBe($first->id)
        ->and($second->versions()->count())->toBe(2)
        ->and(Document::query()->where('document_type_id', $first->document_type_id)
            ->where('documentable_id', $s['booking']->id)->count())->toBe(1);
});

it('rejects generation for a booking that is not confirmed (47)', function () {
    $draft = Booking::factory()->create(['status' => 'draft']);

    expect(fn () => app(GeneratePlotKycReceiptAction::class)->handle($draft, registryOfficer()))
        ->toThrow(DomainException::class);
});

it('rejects generation for a booking with no buyers (48)', function () {
    $s = confirmedBookingScenario();
    $s['booking']->bookingBuyers()->delete();

    expect(fn () => app(GeneratePlotKycReceiptAction::class)->handle($s['booking']->fresh(), registryOfficer()))
        ->toThrow(DomainException::class);
});

it('persists the Vikray Muly amount and both witnesses when supplied (49)', function () {
    $s = confirmedBookingScenario();

    app(GeneratePlotKycReceiptAction::class)->handle($s['booking'], registryOfficer(), [
        'vikray_muly_amount' => '725000',
        'witnesses' => [
            ['name' => 'Suresh Yadav', 'address' => 'Village Road', 'mobile' => '9000000001'],
            ['name' => 'Mahesh Gupta', 'address' => 'Market Street', 'mobile' => '9000000002'],
        ],
    ]);

    $fresh = $s['booking']->fresh('witnesses');

    expect($fresh->vikray_muly_amount)->toBe('725000.00')
        ->and($fresh->witnesses)->toHaveCount(2)
        ->and($fresh->witnesses[0]->name)->toBe('Suresh Yadav')
        ->and($fresh->witnesses[1]->name)->toBe('Mahesh Gupta');
});

it('generation never requires Vikray Muly or witnesses — both stay optional (50)', function () {
    $s = confirmedBookingScenario();

    $document = app(GeneratePlotKycReceiptAction::class)->handle($s['booking'], registryOfficer());

    expect($document)->not->toBeNull()
        ->and($s['booking']->fresh()->vikray_muly_amount)->toBeNull()
        ->and($s['booking']->fresh('witnesses')->witnesses)->toHaveCount(0);
});

it('blanking a previously-set witness removes it on regeneration (51)', function () {
    $s = confirmedBookingScenario();

    app(GeneratePlotKycReceiptAction::class)->handle($s['booking'], registryOfficer(), [
        'witnesses' => [['name' => 'Suresh Yadav'], ['name' => 'Mahesh Gupta']],
    ]);
    expect($s['booking']->fresh('witnesses')->witnesses)->toHaveCount(2);

    app(GeneratePlotKycReceiptAction::class)->handle($s['booking']->fresh(), registryOfficer(), [
        'witnesses' => [['name' => 'Suresh Yadav'], ['name' => '']],
    ]);

    $fresh = $s['booking']->fresh('witnesses');
    expect($fresh->witnesses)->toHaveCount(1)
        ->and($fresh->witnesses[0]->witness_number)->toBe(1);
});

it('records a document timeline event on generation (52)', function () {
    $s = confirmedBookingScenario();

    $document = app(GeneratePlotKycReceiptAction::class)->handle($s['booking'], registryOfficer());

    expect(DocumentActivity::query()
        ->where('booking_id', $s['booking']->id)
        ->where('type', DocumentActivityType::PlotKycReceiptGenerated->value)
        ->exists())->toBeTrue();

    expect($document)->not->toBeNull();
});

it('the PLOT_KYC_RECEIPT document type is seeded idempotently, Booking-scoped, not a new document system (53)', function () {
    $type = docType('PLOT_KYC_RECEIPT');

    expect($type->applies_to->value)->toBe('booking');

    // seeding again does not duplicate the row
    (new DocumentTypeSeeder)->run();
    expect(DocumentType::query()->where('code', 'PLOT_KYC_RECEIPT')->count())->toBe(1);
});
