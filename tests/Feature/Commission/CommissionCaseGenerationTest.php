<?php

declare(strict_types=1);

use App\Actions\Bookings\CancelBookingAction;
use App\Actions\Commission\CreateCommissionSchemeVersion;
use App\Actions\Commission\GenerateCommissionCases;
use App\Actions\Commission\PublishCommissionScheme;
use App\Actions\Commission\RecalculateCommissionCase;
use App\Actions\Commission\SaveCommissionRule;
use App\Actions\Partners\AuthorizePartnerForProjectAction;
use App\Actions\Partners\SetBookingPartnerAttribution;
use App\Enums\CommissionCaseEventType;
use App\Enums\CommissionCaseStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\CommissionCalculation;
use App\Models\CommissionCase;
use App\Models\CommissionScheme;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

/**
 * A confirmed ₹50L booking with a default 2% published scheme and one partner
 * attributed 100%.
 *
 * @return array{actor: User, booking: Booking, partner: Partner, scheme: CommissionScheme}
 */
function commissionWorld(string $finalAmount = '5000000'): array
{
    $s = confirmedBookingScenario($finalAmount);
    $actor = User::factory()->create();

    $scheme = CommissionScheme::factory()->default()->published('2')->create();

    $partner = Partner::factory()->active()->create();
    app(AuthorizePartnerForProjectAction::class)->handle($partner, $s['booking']->project, $actor);
    app(SetBookingPartnerAttribution::class)->handle($s['booking'], [
        ['partner_id' => $partner->id, 'share_percentage' => '100', 'role' => 'primary'],
    ], $actor);

    return ['actor' => $actor, 'booking' => $s['booking']->fresh(), 'partner' => $partner, 'scheme' => $scheme];
}

it('generates one case per attributed partner with an immutable snapshot', function () {
    ['actor' => $actor, 'booking' => $booking, 'partner' => $partner] = commissionWorld();

    $cases = app(GenerateCommissionCases::class)->handle($booking, $actor);

    expect($cases)->toHaveCount(1);
    $case = $cases->first();

    expect($case->case_number)->toStartWith('CMN-')
        ->and($case->status)->toBe(CommissionCaseStatus::PendingReview)
        ->and($case->is_eligible)->toBeTrue()
        ->and((string) $case->commission_amount)->toBe('100000.00') // 2% of 50L
        ->and($case->currentCalculation->snapshot['scheme']['code'])->toBe($case->scheme->code)
        ->and($case->currentCalculation->snapshot['basis']['amount'])->toBe('5000000.00')
        ->and($case->events()->where('type', CommissionCaseEventType::Generated->value)->exists())->toBeTrue();
});

it('is idempotent — re-running generation reuses the case and adds a fresh calculation only', function () {
    ['actor' => $actor, 'booking' => $booking] = commissionWorld();

    $first = app(GenerateCommissionCases::class)->handle($booking, $actor)->first();
    $again = app(GenerateCommissionCases::class)->handle($booking->fresh(), $actor)->first();

    expect($again->id)->toBe($first->id)
        ->and(CommissionCase::count())->toBe(1)
        ->and($again->calculations()->count())->toBe(2)
        ->and((string) $again->commission_amount)->toBe('100000.00');
});

it('splits a co-broker commission by share percentage', function () {
    ['actor' => $actor, 'booking' => $booking, 'partner' => $a] = commissionWorld();
    $b = Partner::factory()->active()->create();
    app(AuthorizePartnerForProjectAction::class)->handle($b, $booking->project, $actor);
    app(SetBookingPartnerAttribution::class)->handle($booking->fresh(), [
        ['partner_id' => $a->id, 'share_percentage' => '60', 'role' => 'primary'],
        ['partner_id' => $b->id, 'share_percentage' => '40', 'role' => 'co_broker'],
    ], $actor);

    $cases = app(GenerateCommissionCases::class)->handle($booking->fresh(), $actor)->keyBy('partner_id');

    // scheme gross = 2% of 50L = 100000 → split 60/40
    expect((string) $cases[$a->id]->commission_amount)->toBe('60000.00')
        ->and((string) $cases[$b->id]->commission_amount)->toBe('40000.00');
});

it('does not touch an approved case on regeneration', function () {
    ['actor' => $actor, 'booking' => $booking] = commissionWorld();
    $case = app(GenerateCommissionCases::class)->handle($booking, $actor)->first();
    $case->forceFill(['status' => CommissionCaseStatus::Approved])->save();

    app(GenerateCommissionCases::class)->handle($booking->fresh(), $actor);

    expect($case->fresh()->calculations()->count())->toBe(1)
        ->and($case->fresh()->status)->toBe(CommissionCaseStatus::Approved);
});

it('keeps a historical figure frozen when the scheme is re-versioned', function () {
    ['actor' => $actor, 'booking' => $booking, 'scheme' => $scheme] = commissionWorld();
    $case = app(GenerateCommissionCases::class)->handle($booking, $actor)->first();
    $firstCalcId = $case->current_calculation_id;

    // Approve it, then publish a richer scheme version.
    $case->forceFill(['status' => CommissionCaseStatus::Approved])->save();

    $v2 = app(CreateCommissionSchemeVersion::class)->handle($scheme->fresh(), $actor);
    app(SaveCommissionRule::class)->handle($v2, ['calc_type' => 'percentage', 'rate' => '5'], $actor);
    app(PublishCommissionScheme::class)->handle($v2->fresh(), $actor);

    // Regeneration must not alter the approved case.
    app(GenerateCommissionCases::class)->handle($booking->fresh(), $actor);

    expect($case->fresh()->current_calculation_id)->toBe($firstCalcId)
        ->and(CommissionCalculation::find($firstCalcId)->commission_amount)->toBe('100000.00');
});

it('cancels a pending case when its partner is dropped from the split', function () {
    ['actor' => $actor, 'booking' => $booking, 'partner' => $partner] = commissionWorld();
    $case = app(GenerateCommissionCases::class)->handle($booking, $actor)->first();

    // Reattribute to a different partner.
    $other = Partner::factory()->active()->create();
    app(AuthorizePartnerForProjectAction::class)->handle($other, $booking->project, $actor);
    app(SetBookingPartnerAttribution::class)->handle($booking->fresh(), [
        ['partner_id' => $other->id, 'share_percentage' => '100', 'role' => 'primary'],
    ], $actor);

    app(GenerateCommissionCases::class)->handle($booking->fresh(), $actor);

    expect($case->fresh()->status)->toBe(CommissionCaseStatus::Cancelled)
        ->and(CommissionCase::where('booking_id', $booking->id)->open()->count())->toBe(1);
});

it('records an ineligible case with a reason and no calculation when collection threshold not met', function () {
    config()->set('commission.eligibility.min_collected_percent', 50);
    ['actor' => $actor, 'booking' => $booking] = commissionWorld();

    $case = app(GenerateCommissionCases::class)->handle($booking, $actor)->first();

    expect($case->is_eligible)->toBeFalse()
        ->and($case->eligibility_reason)->toContain('required')
        ->and($case->currentCalculation)->toBeNull()
        ->and((string) $case->commission_amount)->toBe('0.00');
});

it('locks the attribution split once a commission is approved', function () {
    ['actor' => $actor, 'booking' => $booking, 'partner' => $a] = commissionWorld();
    $case = app(GenerateCommissionCases::class)->handle($booking, $actor)->first();
    $case->forceFill(['status' => CommissionCaseStatus::Approved])->save();

    expect(fn () => app(SetBookingPartnerAttribution::class)->handle($booking->fresh(), [
        ['partner_id' => $a->id, 'share_percentage' => '100', 'role' => 'primary'],
    ], $actor))->toThrow(DomainException::class, 'approved commission');
});

it('cancels pending cases when the booking is cancelled', function () {
    ['actor' => $actor, 'booking' => $booking] = commissionWorld();
    $case = app(GenerateCommissionCases::class)->handle($booking, $actor)->first();

    app(CancelBookingAction::class)->handle($booking->fresh(), $actor, 'test');

    expect($case->fresh()->status)->toBe(CommissionCaseStatus::Cancelled);
});

it('refuses to recalculate an approved case', function () {
    ['actor' => $actor, 'booking' => $booking] = commissionWorld();
    $case = app(GenerateCommissionCases::class)->handle($booking, $actor)->first();
    $case->forceFill(['status' => CommissionCaseStatus::Approved])->save();

    expect(fn () => app(RecalculateCommissionCase::class)->handle($case->fresh(), $actor))
        ->toThrow(DomainException::class);
});

it('recalculates an open case against the current published scheme', function () {
    ['actor' => $actor, 'booking' => $booking, 'scheme' => $scheme] = commissionWorld();
    $case = app(GenerateCommissionCases::class)->handle($booking, $actor)->first();

    $v2 = app(CreateCommissionSchemeVersion::class)->handle($scheme->fresh(), $actor);
    app(SaveCommissionRule::class)->handle($v2, ['calc_type' => 'percentage', 'rate' => '4'], $actor);
    app(PublishCommissionScheme::class)->handle($v2->fresh(), $actor);

    app(RecalculateCommissionCase::class)->handle($case->fresh(), $actor);

    expect((string) $case->fresh()->commission_amount)->toBe('200000.00') // 4% of 50L
        ->and($case->fresh()->calculations()->count())->toBe(2);
});

it('refuses generation for a non-confirmed booking', function () {
    $s = confirmedBookingScenario();
    $s['booking']->forceFill(['status' => 'draft'])->save();

    expect(fn () => app(GenerateCommissionCases::class)->handle($s['booking']->fresh(), User::factory()->create()))
        ->toThrow(DomainException::class, 'confirmed booking');
});

it('blocks a partner with a commission case from being hard-deleted', function () {
    ['actor' => $actor, 'booking' => $booking, 'partner' => $partner] = commissionWorld();
    app(GenerateCommissionCases::class)->handle($booking, $actor);

    expect($partner->fresh()->hasBusinessDependents())->toBeTrue();
});
