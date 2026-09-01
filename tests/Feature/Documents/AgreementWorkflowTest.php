<?php

declare(strict_types=1);

use App\Actions\Agreements\CreateAgreementAction;
use App\Actions\Agreements\PrepareAgreementAction;
use App\Actions\Agreements\TransitionAgreementAction;
use App\Enums\AgreementStatus;
use App\Enums\AgreementType;
use App\Exceptions\DomainException;
use App\Models\Agreement;
use App\Models\Booking;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    seedDocumentMasters();
    Storage::fake('documents');
});

/*
| AGREEMENT (17-23)
*/

it('creates a draft agreement only for a confirmed booking (17)', function () {
    $s = confirmedBookingScenario();
    $agreement = app(CreateAgreementAction::class)->handle($s['booking'], AgreementType::BookingAgreement, registryOfficer());

    expect($agreement->status)->toBe(AgreementStatus::Draft)
        ->and($agreement->agreement_number)->toStartWith('AGR-');

    $draft = Booking::factory()->create(['status' => 'draft']);
    expect(fn () => app(CreateAgreementAction::class)->handle($draft, AgreementType::BookingAgreement, registryOfficer()))
        ->toThrow(DomainException::class);
});

it('generates distinct, sequential agreement numbers (18)', function () {
    $a = app(CreateAgreementAction::class)->handle(confirmedBookingScenario()['booking'], AgreementType::BookingAgreement, registryOfficer());
    $b = app(CreateAgreementAction::class)->handle(confirmedBookingScenario()['booking'], AgreementType::BookingAgreement, registryOfficer());

    expect($a->agreement_number)->not->toBe($b->agreement_number);

    expect(fn () => Agreement::factory()->create(['agreement_number' => $a->agreement_number]))
        ->toThrow(QueryException::class);
});

it('prepares the agreement — freezes booking terms and generates a PDF v1 (19)', function () {
    $s = confirmedBookingScenario();
    $agreement = app(CreateAgreementAction::class)->handle($s['booking'], AgreementType::BookingAgreement, $s['actor']);

    $agreement = app(PrepareAgreementAction::class)->handle($agreement, registryOfficer());

    expect($agreement->status)->toBe(AgreementStatus::Prepared)
        ->and($agreement->terms_snapshot['final_amount'])->toBe($s['booking']->final_amount)
        ->and($agreement->terms_snapshot)->toHaveKey('frozen_at')
        ->and($agreement->document)->not->toBeNull()
        ->and($agreement->document->versions)->toHaveCount(1);
});

it('re-preparing adds a new PDF version and keeps the old one (20)', function () {
    $s = confirmedBookingScenario();
    $agreement = app(CreateAgreementAction::class)->handle($s['booking'], AgreementType::BookingAgreement, $s['actor']);
    $agreement = app(PrepareAgreementAction::class)->handle($agreement, registryOfficer());
    $agreement = app(PrepareAgreementAction::class)->handle($agreement->fresh(), registryOfficer());

    expect($agreement->document->versions()->count())->toBe(2);
});

it('records a signed scan as a new version without overwriting the prepared PDF (21)', function () {
    $s = confirmedBookingScenario();
    $agreement = app(CreateAgreementAction::class)->handle($s['booking'], AgreementType::BookingAgreement, $s['actor']);
    $agreement = app(PrepareAgreementAction::class)->handle($agreement, registryOfficer());
    app(TransitionAgreementAction::class)->send($agreement->fresh(), registryOfficer());

    $agreement = app(TransitionAgreementAction::class)->sign(
        $agreement->fresh(), 'Ramesh Kumar', fakeDocument('signed.pdf'), registryOfficer(),
    );

    expect($agreement->status)->toBe(AgreementStatus::Signed)
        ->and($agreement->signed_by)->toBe('Ramesh Kumar')
        ->and($agreement->signed_at)->not->toBeNull()
        ->and($agreement->document->versions()->count())->toBe(2);
});

it('requires agreements.approve to approve, and only from signed (22)', function () {
    $s = confirmedBookingScenario();
    $agreement = Agreement::factory()->signed()->create(['booking_id' => $s['booking']->id]);

    $noApprove = makeUser(permissions: ['agreements.view', 'agreements.update', 'bookings.view']);
    expect(fn () => app(TransitionAgreementAction::class)->approve($agreement, $noApprove))
        ->toThrow(DomainException::class);

    $agreement = app(TransitionAgreementAction::class)->approve($agreement, registryOfficer());
    expect($agreement->status)->toBe(AgreementStatus::Approved)
        ->and($agreement->approved_by)->not->toBeNull();
});

it('cannot cancel an approved agreement (23)', function () {
    $s = confirmedBookingScenario();
    $agreement = Agreement::factory()->status(AgreementStatus::Approved)->create(['booking_id' => $s['booking']->id]);

    expect(fn () => app(TransitionAgreementAction::class)->cancel($agreement, registryOfficer()))
        ->toThrow(DomainException::class);
});

it('never alters the M6 booking price snapshot when preparing (23)', function () {
    $s = confirmedBookingScenario('1234567');
    $before = $s['booking']->only(['final_amount', 'base_amount', 'subtotal', 'pricing_snapshot']);

    $agreement = app(CreateAgreementAction::class)->handle($s['booking'], AgreementType::BookingAgreement, $s['actor']);
    app(PrepareAgreementAction::class)->handle($agreement, registryOfficer());

    expect($s['booking']->fresh()->only(['final_amount', 'base_amount', 'subtotal', 'pricing_snapshot']))
        ->toBe($before);
});

it('enforces the agreement transition map', function () {
    $s = confirmedBookingScenario();
    $agreement = app(CreateAgreementAction::class)->handle($s['booking'], AgreementType::BookingAgreement, $s['actor']);

    // Draft cannot jump straight to Sent
    expect(fn () => app(TransitionAgreementAction::class)->send($agreement, registryOfficer()))
        ->toThrow(DomainException::class);
});
