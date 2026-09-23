<?php

declare(strict_types=1);

use App\Actions\Bookings\ConfirmBookingAction;
use App\Actions\Bookings\CreateBookingAction;
use App\Actions\Bookings\SubmitBookingAction;
use App\Actions\Commission\GenerateCommissionCases;
use App\Actions\Partners\AuthorizePartnerForProjectAction;
use App\Actions\Partners\SetBookingPartnerAttribution;
use App\Enums\CommissionCaseStatus;
use App\Models\Booking;
use App\Models\CommissionCase;
use App\Models\Partner;
use App\Models\User;
use App\Services\Commission\PromoterLedgerService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

/**
 * A DRAFT booking (real create → submit pipeline, not yet confirmed) for a
 * fixed final_amount, with no promoter attributed.
 *
 * @return array{actor: User, booking: Booking, s: array<string, mixed>}
 */
function draftBookingWithValue(string $finalAmount): array
{
    // The pricing area is derived from the plot, so a 1 sq ft plot at a rate
    // of $finalAmount lands exactly on $finalAmount.
    $s = bookingScenario(['area' => 1]);

    $booking = app(CreateBookingAction::class)->handle(
        bookingPayload($s, ['pricing' => ['base_area' => '1', 'base_rate' => $finalAmount, 'components' => []]]),
        $s['actor'],
    );
    app(SubmitBookingAction::class)->handle($booking, $s['actor']);

    return ['actor' => $s['actor'], 'booking' => $booking->fresh(), 's' => $s];
}

// --- A/B — confirming a booking auto-generates (or doesn't) commission -----

it('A: automatically generates the commission case when a booking with an attributed promoter is confirmed', function () {
    ['actor' => $actor, 'booking' => $booking, 's' => $s] = draftBookingWithValue('450000');
    $partner = Partner::factory()->active()->commission('10')->create();
    app(AuthorizePartnerForProjectAction::class)->handle($partner, $s['project'], $actor);
    app(SetBookingPartnerAttribution::class)->handle($booking, $partner->id, $actor);

    expect(CommissionCase::count())->toBe(0); // not confirmed yet — no auto-generation

    $confirmed = app(ConfirmBookingAction::class)->handle($booking->fresh(), $actor);

    $case = CommissionCase::where('booking_id', $confirmed->id)->where('partner_id', $partner->id)->first();
    expect($case)->not->toBeNull()
        ->and($case->status)->toBe(CommissionCaseStatus::PendingReview)
        ->and((string) $case->commission_amount)->toBe('45000.00'); // 10% of 450,000
});

it('B: does not generate any commission case when a booking with no promoter is confirmed', function () {
    ['actor' => $actor, 'booking' => $booking] = draftBookingWithValue('450000');

    app(ConfirmBookingAction::class)->handle($booking->fresh(), $actor);

    expect(CommissionCase::count())->toBe(0);
});

// --- C/D — attributing a promoter to an ALREADY-confirmed booking ----------

it('C: automatically generates the commission case when a promoter is assigned to an already-confirmed booking', function () {
    ['actor' => $actor, 'booking' => $booking, 's' => $s] = draftBookingWithValue('450000');
    app(ConfirmBookingAction::class)->handle($booking->fresh(), $actor);

    $partner = Partner::factory()->active()->commission('10')->create();
    app(AuthorizePartnerForProjectAction::class)->handle($partner, $s['project'], $actor);

    expect(CommissionCase::count())->toBe(0); // no promoter yet

    app(SetBookingPartnerAttribution::class)->handle($booking->fresh(), $partner->id, $actor);

    $case = CommissionCase::where('booking_id', $booking->id)->where('partner_id', $partner->id)->first();
    expect($case)->not->toBeNull()
        ->and((string) $case->commission_amount)->toBe('45000.00');
});

it('D: does nothing when no promoter is ever assigned to an already-confirmed booking', function () {
    ['actor' => $actor, 'booking' => $booking] = draftBookingWithValue('450000');
    app(ConfirmBookingAction::class)->handle($booking->fresh(), $actor);

    expect(CommissionCase::count())->toBe(0);
});

// --- E/F — idempotency of the new triggers ----------------------------------

it('E: repeated confirmation does not duplicate the commission case', function () {
    ['actor' => $actor, 'booking' => $booking, 's' => $s] = draftBookingWithValue('450000');
    $partner = Partner::factory()->active()->commission('10')->create();
    app(AuthorizePartnerForProjectAction::class)->handle($partner, $s['project'], $actor);
    app(SetBookingPartnerAttribution::class)->handle($booking, $partner->id, $actor);

    app(ConfirmBookingAction::class)->handle($booking->fresh(), $actor);
    // Confirming an already-CONFIRMED booking is a documented no-op in
    // ConfirmBookingAction (returns early, no save(), no observer refire).
    app(ConfirmBookingAction::class)->handle($booking->fresh(), $actor);
    app(ConfirmBookingAction::class)->handle($booking->fresh(), $actor);

    expect(CommissionCase::where('booking_id', $booking->id)->count())->toBe(1);
});

it('F: repeated promoter attribution save (same promoter) does not duplicate the commission case', function () {
    ['actor' => $actor, 'booking' => $booking, 's' => $s] = draftBookingWithValue('450000');
    app(ConfirmBookingAction::class)->handle($booking->fresh(), $actor);

    $partner = Partner::factory()->active()->commission('10')->create();
    app(AuthorizePartnerForProjectAction::class)->handle($partner, $s['project'], $actor);

    app(SetBookingPartnerAttribution::class)->handle($booking->fresh(), $partner->id, $actor);
    app(SetBookingPartnerAttribution::class)->handle($booking->fresh(), $partner->id, $actor);
    app(SetBookingPartnerAttribution::class)->handle($booking->fresh(), $partner->id, $actor);

    expect(CommissionCase::where('booking_id', $booking->id)->count())->toBe(1)
        ->and(CommissionCase::where('booking_id', $booking->id)->first()->calculations()->count())->toBe(1);
});

it('K: does not duplicate a commission case that was already generated manually before the automatic trigger fires', function () {
    ['actor' => $actor, 'booking' => $booking, 's' => $s] = draftBookingWithValue('450000');
    $partner = Partner::factory()->active()->commission('10')->create();
    app(AuthorizePartnerForProjectAction::class)->handle($partner, $s['project'], $actor);
    app(SetBookingPartnerAttribution::class)->handle($booking, $partner->id, $actor);

    // A confirmed booking already has a manually-generated case (e.g. from a
    // prior manual "Generate" click) — this simulates that pre-existing state
    // by confirming (which auto-generates) and then invoking the manual
    // action again, exactly as the "Generate" button would.
    app(ConfirmBookingAction::class)->handle($booking->fresh(), $actor);
    app(GenerateCommissionCases::class)->handle($booking->fresh(), $actor);

    expect(CommissionCase::where('booking_id', $booking->id)->count())->toBe(1);
});

// --- G/H — the commission figure itself is unchanged by the new wiring -----

it('G: the auto-generated commission uses the frozen promoter percentage snapshotted at attribution time', function () {
    ['actor' => $actor, 'booking' => $booking, 's' => $s] = draftBookingWithValue('450000');
    $partner = Partner::factory()->active()->commission('10')->create();
    app(AuthorizePartnerForProjectAction::class)->handle($partner, $s['project'], $actor);
    app(SetBookingPartnerAttribution::class)->handle($booking, $partner->id, $actor);

    // Master rate changes AFTER attribution, before confirmation.
    $partner->forceFill(['commission_percentage' => '20'])->save();

    app(ConfirmBookingAction::class)->handle($booking->fresh(), $actor);

    $case = CommissionCase::where('booking_id', $booking->id)->first();
    expect((string) $case->commission_amount)->toBe('45000.00'); // still 10%, not 20%
});

it('H: the auto-generated commission uses booking.final_amount as the base', function () {
    ['actor' => $actor, 'booking' => $booking, 's' => $s] = draftBookingWithValue('999999.83');
    $partner = Partner::factory()->active()->commission('10')->create();
    app(AuthorizePartnerForProjectAction::class)->handle($partner, $s['project'], $actor);
    app(SetBookingPartnerAttribution::class)->handle($booking, $partner->id, $actor);

    app(ConfirmBookingAction::class)->handle($booking->fresh(), $actor);

    expect((string) $booking->fresh()->final_amount)->toBe('999999.83');
    $case = CommissionCase::where('booking_id', $booking->id)->first();
    expect((string) $case->commission_amount)->toBe('99999.98'); // 10% of 999999.83, HALF_UP
});

// --- I/J — advance is automatically adjusted by the automatic trigger ------

it('I: available promoter advance is automatically adjusted when the commission is auto-generated', function () {
    ['actor' => $actor, 'booking' => $booking, 's' => $s] = draftBookingWithValue('450000');
    $partner = Partner::factory()->active()->commission('10')->create();
    app(PromoterLedgerService::class)->giveAdvance($partner, Money::of('50000'), 'seed advance', $actor);
    app(AuthorizePartnerForProjectAction::class)->handle($partner, $s['project'], $actor);
    app(SetBookingPartnerAttribution::class)->handle($booking, $partner->id, $actor);

    app(ConfirmBookingAction::class)->handle($booking->fresh(), $actor);

    $case = CommissionCase::where('booking_id', $booking->id)->first();
    expect((string) $case->commission_amount)->toBe('45000.00')
        ->and((string) $case->advance_adjusted_amount)->toBe('45000.00')
        ->and((string) $case->payable_amount)->toBe('0.00')
        ->and(app(PromoterLedgerService::class)->advanceBalance($partner->fresh())->store())->toBe('5000.00');
});

it('J: with no advance, the full auto-generated commission becomes payable', function () {
    ['actor' => $actor, 'booking' => $booking, 's' => $s] = draftBookingWithValue('450000');
    $partner = Partner::factory()->active()->commission('10')->create(); // no advance given
    app(AuthorizePartnerForProjectAction::class)->handle($partner, $s['project'], $actor);
    app(SetBookingPartnerAttribution::class)->handle($booking, $partner->id, $actor);

    app(ConfirmBookingAction::class)->handle($booking->fresh(), $actor);

    $case = CommissionCase::where('booking_id', $booking->id)->first();
    expect((string) $case->commission_amount)->toBe('45000.00')
        ->and((string) $case->advance_adjusted_amount)->toBe('0.00')
        ->and((string) $case->payable_amount)->toBe('45000.00');
});
