<?php

declare(strict_types=1);

use App\Actions\Commission\GenerateCommissionCases;
use App\Actions\Commission\RecordCommissionPayout;
use App\Actions\Commission\ReverseCommissionCase;
use App\Actions\Partners\AuthorizePartnerForProjectAction;
use App\Actions\Partners\CreatePartnerAction;
use App\Actions\Partners\SetBookingPartnerAttribution;
use App\Enums\CommissionCaseStatus;
use App\Enums\PartnerType;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\CommissionCase;
use App\Models\Partner;
use App\Models\User;
use App\Services\Commission\PromoterLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

/**
 * A promoter with a 100% commission rate (so a booking's final_amount IS the
 * gross commission, for arithmetic that is trivial to assert on) plus a
 * booking of the requested value already attributed and eligible.
 *
 * @return array{actor: User, partner: Partner, booking: Booking}
 */
function promoterWorld(string $bookingValue, ?string $initialAdvance = null): array
{
    $actor = User::factory()->create();

    $partner = app(CreatePartnerAction::class)->handle([
        'type' => PartnerType::Individual->value,
        'name' => 'Raju',
        'company_name' => null, 'contact_person' => null,
        'phone' => '9800000001', 'alternate_phone' => null, 'email' => null,
        'address' => null, 'state_id' => null, 'city_id' => null, 'pincode' => null,
        'pan_number' => null, 'rera_number' => null,
        'commission_percentage' => '100',
        'initial_advance' => $initialAdvance,
        'bank_account_name' => null, 'bank_account_number' => null, 'bank_ifsc' => null, 'bank_name' => null,
        'notes' => null,
    ], $actor);
    app(\App\Actions\Partners\ChangePartnerStatusAction::class)->handle($partner, \App\Enums\PartnerStatus::Active, $actor);

    $s = confirmedBookingScenario($bookingValue);
    app(AuthorizePartnerForProjectAction::class)->handle($partner, $s['booking']->project, $actor);
    app(SetBookingPartnerAttribution::class)->handle($s['booking'], $partner->id, $actor);

    return ['actor' => $actor, 'partner' => $partner->fresh(), 'booking' => $s['booking']->fresh()];
}

it('records the initial advance as a real ledger transaction on promoter creation', function () {
    ['partner' => $partner] = promoterWorld('100', '50000');

    $ledger = app(PromoterLedgerService::class);
    expect($ledger->advanceBalance($partner)->store())->toBe('50000.00')
        ->and($partner->ledgerEntries()->count())->toBe(1)
        ->and($partner->ledgerEntries()->first()->type->value)->toBe('advance_given');
});

it('adjusts a commission against the promoter advance, leaving a remainder and zero payable', function () {
    // ₹50,000 advance; ₹45,000 commission → ₹5,000 advance left, ₹0 payable.
    ['actor' => $actor, 'partner' => $partner, 'booking' => $booking] = promoterWorld('45000', '50000');

    $case = app(GenerateCommissionCases::class)->handle($booking, $actor)->first();

    expect((string) $case->commission_amount)->toBe('45000.00')
        ->and((string) $case->advance_adjusted_amount)->toBe('45000.00')
        ->and((string) $case->payable_amount)->toBe('0.00')
        ->and(app(PromoterLedgerService::class)->advanceBalance($partner->fresh())->store())->toBe('5000.00');
});

it('exhausts the remaining advance on the next commission and makes the rest payable', function () {
    ['actor' => $actor, 'partner' => $partner, 'booking' => $bookingA] = promoterWorld('45000', '50000');
    app(GenerateCommissionCases::class)->handle($bookingA, $actor);
    expect(app(PromoterLedgerService::class)->advanceBalance($partner->fresh())->store())->toBe('5000.00');

    $sB = confirmedBookingScenario('30000');
    app(AuthorizePartnerForProjectAction::class)->handle($partner->fresh(), $sB['booking']->project, $actor);
    app(SetBookingPartnerAttribution::class)->handle($sB['booking'], $partner->id, $actor);
    $caseB = app(GenerateCommissionCases::class)->handle($sB['booking']->fresh(), $actor)->first();

    expect((string) $caseB->commission_amount)->toBe('30000.00')
        ->and((string) $caseB->advance_adjusted_amount)->toBe('5000.00')
        ->and((string) $caseB->payable_amount)->toBe('25000.00')
        ->and(app(PromoterLedgerService::class)->advanceBalance($partner->fresh())->store())->toBe('0.00');
});

it('zeroes both advance and payable when commission exactly equals the advance', function () {
    ['actor' => $actor, 'partner' => $partner, 'booking' => $booking] = promoterWorld('50000', '50000');

    $case = app(GenerateCommissionCases::class)->handle($booking, $actor)->first();

    expect((string) $case->advance_adjusted_amount)->toBe('50000.00')
        ->and((string) $case->payable_amount)->toBe('0.00')
        ->and(app(PromoterLedgerService::class)->advanceBalance($partner->fresh())->store())->toBe('0.00');
});

it('leaves the correct remaining advance when commission is less than the advance', function () {
    ['actor' => $actor, 'partner' => $partner, 'booking' => $booking] = promoterWorld('12000', '50000');

    app(GenerateCommissionCases::class)->handle($booking, $actor);

    expect(app(PromoterLedgerService::class)->advanceBalance($partner->fresh())->store())->toBe('38000.00');
});

it('generates no commission case at all when the booking has no promoter', function () {
    $s = confirmedBookingScenario('500000');

    $cases = app(GenerateCommissionCases::class)->handle($s['booking'], $s['actor']);

    expect($cases)->toHaveCount(0)
        ->and(CommissionCase::count())->toBe(0);
});

it('never double-consumes the advance across a repeated booking lifecycle (generate twice)', function () {
    ['actor' => $actor, 'partner' => $partner, 'booking' => $booking] = promoterWorld('45000', '50000');

    // Simulate the booking being "re-confirmed" / the screen being refreshed —
    // generation is called twice in a row while the case is still pending.
    app(GenerateCommissionCases::class)->handle($booking, $actor);
    app(GenerateCommissionCases::class)->handle($booking->fresh(), $actor);

    expect(CommissionCase::where('booking_id', $booking->id)->count())->toBe(1)
        ->and(app(PromoterLedgerService::class)->advanceBalance($partner->fresh())->store())->toBe('5000.00');
});

it('restores the advance through a compensating ledger entry when a commission is reversed', function () {
    ['actor' => $actor, 'partner' => $partner, 'booking' => $booking] = promoterWorld('45000', '50000');
    $case = app(GenerateCommissionCases::class)->handle($booking, $actor)->first();
    $case->forceFill(['status' => CommissionCaseStatus::Approved])->save();

    expect(app(PromoterLedgerService::class)->advanceBalance($partner->fresh())->store())->toBe('5000.00');

    app(ReverseCommissionCase::class)->handle($case->fresh(), $actor, 'Booking cancelled after approval');

    expect(app(PromoterLedgerService::class)->advanceBalance($partner->fresh())->store())->toBe('50000.00');

    // Original + reversal both remain in history — nothing is deleted.
    $entries = $partner->fresh()->ledgerEntries;
    expect($entries)->toHaveCount(3) // advance_given, commission, advance_adjustment_reversed
        ->and($entries->firstWhere('type', \App\Enums\PromoterLedgerEntryType::Commission)->reversed_at)->not->toBeNull();
});

it('records a new advance as a fresh ledger row without touching the original transaction', function () {
    ['partner' => $partner] = promoterWorld('100', '50000');
    $original = $partner->ledgerEntries()->first();

    app(PromoterLedgerService::class)->giveAdvance($partner, \App\Support\Money::of('20000'), 'Top-up', User::factory()->create());

    expect($partner->ledgerEntries()->count())->toBe(2)
        ->and($original->fresh()->advance_amount)->toBe('50000.00') // untouched
        ->and(app(PromoterLedgerService::class)->advanceBalance($partner->fresh())->store())->toBe('70000.00');
});

it('caps a payout at the net payable commission, never the gross figure', function () {
    // No advance at all → gross === payable, so we can assert the cap directly.
    ['actor' => $actor, 'partner' => $partner, 'booking' => $booking] = promoterWorld('30000');
    $case = app(GenerateCommissionCases::class)->handle($booking, $actor)->first();
    $case->forceFill(['status' => CommissionCaseStatus::Approved])->save();

    expect((string) $case->payable_amount)->toBe('30000.00');

    expect(fn () => app(RecordCommissionPayout::class)->handle($case->fresh(), [
        'amount' => '30000.01', 'method' => 'bank_transfer', 'paid_on' => now()->toDateString(),
    ], $actor))->toThrow(DomainException::class, 'payable');

    app(RecordCommissionPayout::class)->handle($case->fresh(), [
        'amount' => '30000.00', 'method' => 'bank_transfer', 'paid_on' => now()->toDateString(),
    ], $actor);

    expect($case->fresh()->status)->toBe(CommissionCaseStatus::Paid);
});

it('caps a payout at the payable amount when advance has already consumed part of the gross', function () {
    ['actor' => $actor, 'partner' => $partner, 'booking' => $booking] = promoterWorld('45000', '50000');
    $case = app(GenerateCommissionCases::class)->handle($booking, $actor)->first();
    $case->forceFill(['status' => CommissionCaseStatus::Approved])->save();

    expect((string) $case->fresh()->payable_amount)->toBe('0.00');

    expect(fn () => app(RecordCommissionPayout::class)->handle($case->fresh(), [
        'amount' => '0.01', 'method' => 'bank_transfer', 'paid_on' => now()->toDateString(),
    ], $actor))->toThrow(DomainException::class, 'payable');
});
