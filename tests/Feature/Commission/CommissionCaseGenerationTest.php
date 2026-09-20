<?php

declare(strict_types=1);

use App\Actions\Bookings\CancelBookingAction;
use App\Actions\Commission\GenerateCommissionCases;
use App\Actions\Commission\RecalculateCommissionCase;
use App\Actions\Partners\AuthorizePartnerForProjectAction;
use App\Actions\Partners\SetBookingPartnerAttribution;
use App\Enums\CommissionCaseEventType;
use App\Enums\CommissionCaseStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\CommissionCase;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

/**
 * A confirmed ₹50L booking with one 2%-commission promoter attributed — no
 * advance, so gross === payable for these generation-focused tests.
 *
 * @return array{actor: User, booking: Booking, partner: Partner}
 */
function commissionWorld(string $finalAmount = '5000000', string $commissionPercentage = '2'): array
{
    $s = confirmedBookingScenario($finalAmount);
    $actor = User::factory()->create();

    $partner = Partner::factory()->active()->commission($commissionPercentage)->create();
    app(AuthorizePartnerForProjectAction::class)->handle($partner, $s['booking']->project, $actor);
    app(SetBookingPartnerAttribution::class)->handle($s['booking'], $partner->id, $actor);

    return ['actor' => $actor, 'booking' => $s['booking']->fresh(), 'partner' => $partner];
}

it('generates one case for the booking promoter with an immutable snapshot', function () {
    ['actor' => $actor, 'booking' => $booking, 'partner' => $partner] = commissionWorld();

    $cases = app(GenerateCommissionCases::class)->handle($booking, $actor);

    expect($cases)->toHaveCount(1);
    $case = $cases->first();

    expect($case->case_number)->toStartWith('CMN-')
        ->and($case->status)->toBe(CommissionCaseStatus::PendingReview)
        ->and($case->is_eligible)->toBeTrue()
        ->and((string) $case->commission_amount)->toBe('100000.00') // 2% of 50L
        ->and((string) $case->payable_amount)->toBe('100000.00') // no advance to adjust
        ->and($case->currentCalculation->snapshot['promoter']['id'])->toBe($partner->id)
        ->and($case->currentCalculation->snapshot['basis']['amount'])->toBe('5000000.00')
        ->and($case->events()->where('type', CommissionCaseEventType::Generated->value)->exists())->toBeTrue();
});

it('is idempotent — re-running generation reuses the case and adds a fresh calculation only', function () {
    // commissionWorld() itself already auto-generates calculation #1 (the
    // promoter is attributed to an already-confirmed booking) — see
    // SetBookingPartnerAttribution's automatic-generation wiring.
    ['actor' => $actor, 'booking' => $booking] = commissionWorld();

    $first = app(GenerateCommissionCases::class)->handle($booking, $actor)->first();
    $again = app(GenerateCommissionCases::class)->handle($booking->fresh(), $actor)->first();

    expect($again->id)->toBe($first->id)
        ->and(CommissionCase::count())->toBe(1)
        ->and($again->calculations()->count())->toBe(3) // auto-generated + 2 explicit re-runs
        ->and((string) $again->commission_amount)->toBe('100000.00');
});

it('does not touch an approved case on regeneration', function () {
    ['actor' => $actor, 'booking' => $booking] = commissionWorld(); // auto-generates calculation #1
    $case = app(GenerateCommissionCases::class)->handle($booking, $actor)->first(); // calculation #2
    $case->forceFill(['status' => CommissionCaseStatus::Approved])->save();

    app(GenerateCommissionCases::class)->handle($booking->fresh(), $actor);

    expect($case->fresh()->calculations()->count())->toBe(2)
        ->and($case->fresh()->status)->toBe(CommissionCaseStatus::Approved);
});

it('cancels a pending case when the promoter is removed from the booking', function () {
    ['actor' => $actor, 'booking' => $booking] = commissionWorld();
    $case = app(GenerateCommissionCases::class)->handle($booking, $actor)->first();

    // Reattribute to a different promoter.
    $other = Partner::factory()->active()->commission('2')->create();
    app(AuthorizePartnerForProjectAction::class)->handle($other, $booking->project, $actor);
    app(SetBookingPartnerAttribution::class)->handle($booking->fresh(), $other->id, $actor);

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

it('records an ineligible case when the promoter has no commission % configured', function () {
    $s = confirmedBookingScenario('5000000');
    $actor = User::factory()->create();
    $partner = Partner::factory()->active()->create(); // no commission_percentage
    app(AuthorizePartnerForProjectAction::class)->handle($partner, $s['booking']->project, $actor);
    app(SetBookingPartnerAttribution::class)->handle($s['booking'], $partner->id, $actor);

    $case = app(GenerateCommissionCases::class)->handle($s['booking']->fresh(), $actor)->first();

    expect($case->is_eligible)->toBeFalse()
        ->and($case->eligibility_reason)->toContain('commission %');
});

it('locks the attribution once a commission is approved', function () {
    ['actor' => $actor, 'booking' => $booking, 'partner' => $a] = commissionWorld();
    $case = app(GenerateCommissionCases::class)->handle($booking, $actor)->first();
    $case->forceFill(['status' => CommissionCaseStatus::Approved])->save();

    $other = Partner::factory()->active()->commission('2')->create();

    expect(fn () => app(SetBookingPartnerAttribution::class)->handle($booking->fresh(), $other->id, $actor))
        ->toThrow(DomainException::class, 'approved commission');
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

it('recalculates an open case against the current booking final amount', function () {
    ['actor' => $actor, 'booking' => $booking] = commissionWorld(); // auto-generates calculation #1
    $case = app(GenerateCommissionCases::class)->handle($booking, $actor)->first(); // calculation #2

    // A legitimate booking-value correction while the case is still pending.
    $booking->forceFill(['final_amount' => '6000000'])->save();
    app(RecalculateCommissionCase::class)->handle($case->fresh(), $actor);

    expect((string) $case->fresh()->commission_amount)->toBe('120000.00') // 2% of 60L
        ->and($case->fresh()->calculations()->count())->toBe(3);
});

it('does NOT pick up a promoter master commission-rate change on recalculation — the rate is frozen at attribution time', function () {
    ['actor' => $actor, 'booking' => $booking, 'partner' => $partner] = commissionWorld();
    $case = app(GenerateCommissionCases::class)->handle($booking, $actor)->first();

    $partner->forceFill(['commission_percentage' => '4'])->save();
    app(RecalculateCommissionCase::class)->handle($case->fresh(), $actor);

    expect((string) $case->fresh()->commission_amount)->toBe('100000.00'); // still 2% of 50L, not 4%
});

it('refuses generation for a non-confirmed booking', function () {
    $s = confirmedBookingScenario();
    $s['booking']->forceFill(['status' => 'draft'])->save();

    expect(fn () => app(GenerateCommissionCases::class)->handle($s['booking']->fresh(), User::factory()->create()))
        ->toThrow(DomainException::class, 'confirmed booking');
});

it('blocks a promoter with a commission case from being hard-deleted', function () {
    ['actor' => $actor, 'booking' => $booking, 'partner' => $partner] = commissionWorld();
    app(GenerateCommissionCases::class)->handle($booking, $actor);

    expect($partner->fresh()->hasBusinessDependents())->toBeTrue();
});
