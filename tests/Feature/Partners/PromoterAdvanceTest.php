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

/**
 * A promoter with an optional initial advance and NO booking attribution —
 * for tests that only care about the advance ledger in isolation, since
 * attributing a promoter to an already-confirmed booking now auto-generates
 * a commission (see SetBookingPartnerAttribution) and would add noise here.
 *
 * @return array{actor: User, partner: Partner}
 */
function promoterOnly(?string $initialAdvance = null): array
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

    return ['actor' => $actor, 'partner' => $partner->fresh()];
}

it('records the initial advance as a real ledger transaction on promoter creation', function () {
    ['partner' => $partner] = promoterOnly('50000');

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
    // promoterWorld() already auto-generated the case (attribution onto an
    // already-confirmed booking) — fetch it rather than recalculating again,
    // so the ledger stays exactly: advance_given, commission, reversal.
    $case = CommissionCase::where('booking_id', $booking->id)->where('partner_id', $partner->id)->firstOrFail();
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
    ['partner' => $partner] = promoterOnly('50000');
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

it('leaves the correct remaining payable after a partial payout', function () {
    // Gross 30,000, advance-adjusted 5,000 → payable 25,000.
    ['actor' => $actor, 'partner' => $partner, 'booking' => $bookingA] = promoterWorld('45000', '50000');
    app(GenerateCommissionCases::class)->handle($bookingA, $actor);

    $sB = confirmedBookingScenario('30000');
    app(AuthorizePartnerForProjectAction::class)->handle($partner->fresh(), $sB['booking']->project, $actor);
    app(SetBookingPartnerAttribution::class)->handle($sB['booking'], $partner->id, $actor);
    $caseB = app(GenerateCommissionCases::class)->handle($sB['booking']->fresh(), $actor)->first();
    $caseB->forceFill(['status' => CommissionCaseStatus::Approved])->save();

    app(RecordCommissionPayout::class)->handle($caseB->fresh(), [
        'amount' => '10000', 'method' => 'bank_transfer', 'paid_on' => now()->toDateString(),
    ], $actor);

    $fresh = $caseB->fresh();
    expect((string) $fresh->commission_amount)->toBe('30000.00')
        ->and((string) $fresh->advance_adjusted_amount)->toBe('5000.00')
        ->and((string) $fresh->paid_amount)->toBe('10000.00')
        ->and($fresh->outstandingAmount())->toBe('15000.00')
        ->and($fresh->status)->toBe(CommissionCaseStatus::PartiallyPaid);
});

// --- §17.B — exact rounding on an odd decimal booking amount ---------------

it('computes an exact HALF_UP-rounded commission on an odd decimal booking amount', function () {
    // 499999.83 × 10% = 49999.983 → rounds to 49999.98 (3rd decimal is 3, rounds down).
    $actor = User::factory()->create();
    $partner = Partner::factory()->active()->commission('10')->create();
    $s = confirmedBookingScenario('499999.83');
    app(AuthorizePartnerForProjectAction::class)->handle($partner, $s['booking']->project, $actor);
    app(SetBookingPartnerAttribution::class)->handle($s['booking'], $partner->id, $actor);

    $case = app(GenerateCommissionCases::class)->handle($s['booking']->fresh(), $actor)->first();

    expect((string) $case->commission_amount)->toBe('49999.98');
});

// --- §17.E — aggregate totals across multiple bookings for one promoter ----

it('aggregates booking value and gross commission correctly across multiple bookings', function () {
    $actor = User::factory()->create();
    $partner = Partner::factory()->active()->commission('10')->create();

    foreach (['450000', '600000', '300000'] as $value) {
        $s = confirmedBookingScenario($value);
        app(AuthorizePartnerForProjectAction::class)->handle($partner, $s['booking']->project, $actor);
        app(SetBookingPartnerAttribution::class)->handle($s['booking'], $partner->id, $actor);
        app(GenerateCommissionCases::class)->handle($s['booking']->fresh(), $actor);
    }

    $cases = CommissionCase::where('partner_id', $partner->id)->with('booking:id,final_amount')->get();
    expect($cases)->toHaveCount(3)
        ->and((string) $cases->sum(fn ($c) => (float) $c->booking->final_amount))->toBe('1350000')
        ->and((string) $cases->sum('commission_amount'))->toBe('135000');
});

// --- §17.F — a promoter's master rate change must never drift an already-attributed booking ---

it('keeps a booking pinned to the commission rate that was in force when the promoter was attached, even after the promoter master rate changes and the case is recalculated', function () {
    $actor = User::factory()->create();
    $partner = Partner::factory()->active()->commission('10')->create();
    $s = confirmedBookingScenario('450000');
    app(AuthorizePartnerForProjectAction::class)->handle($partner, $s['booking']->project, $actor);
    app(SetBookingPartnerAttribution::class)->handle($s['booking'], $partner->id, $actor);

    $case = app(GenerateCommissionCases::class)->handle($s['booking']->fresh(), $actor)->first();
    expect((string) $case->commission_amount)->toBe('45000.00'); // 10% of 450,000

    // The promoter's master rate changes AFTER attribution, while the case is
    // still pending (not yet approved).
    $partner->forceFill(['commission_percentage' => '12'])->save();

    app(\App\Actions\Commission\RecalculateCommissionCase::class)->handle($case->fresh(), $actor);

    // Must still be 10% — the rate is frozen on the attribution, not read
    // live from the partner's current master rate.
    expect((string) $case->fresh()->commission_amount)->toBe('45000.00');

    // A NEW booking attributed afterward legitimately picks up the new 12%.
    $s2 = confirmedBookingScenario('450000');
    app(AuthorizePartnerForProjectAction::class)->handle($partner->fresh(), $s2['booking']->project, $actor);
    app(SetBookingPartnerAttribution::class)->handle($s2['booking'], $partner->id, $actor);
    $case2 = app(GenerateCommissionCases::class)->handle($s2['booking']->fresh(), $actor)->first();

    expect((string) $case2->commission_amount)->toBe('54000.00'); // 12% of 450,000
});

// --- §17.J — discount must reduce the commission base ---------------------

it('calculates commission on the post-discount final amount, not the pre-discount base', function () {
    $actor = User::factory()->create();
    $partner = Partner::factory()->active()->commission('10')->create();

    $project = \App\Models\Project::factory()->create();
    $block = \App\Models\Block::factory()->create(['project_id' => $project->id]);
    $plot = \App\Models\Plot::factory()->create([
        'project_id' => $project->id, 'block_id' => $block->id,
        'status' => \App\Enums\PlotStatus::Booked->value,
    ]);
    $booking = Booking::factory()->confirmed()->forPlot($plot)->create([
        'base_amount' => '500000',
        'subtotal' => '500000',
        'discount_amount' => '50000',
        'final_amount' => '450000', // base 500,000 − discount 50,000
    ]);
    \App\Models\BookingBuyer::factory()->create([
        'booking_id' => $booking->id,
        'buyer_id' => \App\Models\Buyer::factory()->create(['status' => 'active'])->id,
        'ownership_percentage' => 100, 'is_primary' => true,
    ]);

    app(AuthorizePartnerForProjectAction::class)->handle($partner, $booking->project, $actor);
    app(SetBookingPartnerAttribution::class)->handle($booking, $partner->id, $actor);
    $case = app(GenerateCommissionCases::class)->handle($booking->fresh(), $actor)->first();

    expect((string) $case->commission_amount)->toBe('45000.00') // 10% of 450,000, NOT 50,000
        ->and((string) $case->commission_amount)->not->toBe('50000.00');
});

// --- §13 — booking cancellation must reverse a pending case's advance too --

it('reverses the advance adjustment when a booking is cancelled while its commission case is still pending', function () {
    ['actor' => $actor, 'partner' => $partner, 'booking' => $booking] = promoterWorld('45000', '50000');
    app(GenerateCommissionCases::class)->handle($booking, $actor);

    expect(app(PromoterLedgerService::class)->advanceBalance($partner->fresh())->store())->toBe('5000.00');

    app(\App\Actions\Bookings\CancelBookingAction::class)->handle($booking->fresh(), $actor, 'test cancellation');

    expect(app(PromoterLedgerService::class)->advanceBalance($partner->fresh())->store())->toBe('50000.00');
});

// --- §9 — exact chronological three-booking scenario from the audit --------

it('matches the exact chronological three-booking advance/payable walk-through from the audit', function () {
    ['actor' => $actor, 'partner' => $partner, 'booking' => $bookingA] = promoterWorld('45000', '50000');
    app(GenerateCommissionCases::class)->handle($bookingA, $actor); // gross 45k → adj 45k, payable 0, balance 5k

    $sB = confirmedBookingScenario('60000');
    app(AuthorizePartnerForProjectAction::class)->handle($partner->fresh(), $sB['booking']->project, $actor);
    app(SetBookingPartnerAttribution::class)->handle($sB['booking'], $partner->id, $actor);
    app(GenerateCommissionCases::class)->handle($sB['booking']->fresh(), $actor); // gross 60k → adj 5k, payable 55k, balance 0

    $sC = confirmedBookingScenario('30000');
    app(AuthorizePartnerForProjectAction::class)->handle($partner->fresh(), $sC['booking']->project, $actor);
    app(SetBookingPartnerAttribution::class)->handle($sC['booking'], $partner->id, $actor);
    app(GenerateCommissionCases::class)->handle($sC['booking']->fresh(), $actor); // gross 30k → adj 0, payable 30k

    $cases = CommissionCase::where('partner_id', $partner->id)->get();

    expect((string) $cases->sum('commission_amount'))->toBe('135000') // gross
        ->and((string) $cases->sum('advance_adjusted_amount'))->toBe('50000')
        ->and((string) $cases->sum('payable_amount'))->toBe('85000')
        ->and(app(PromoterLedgerService::class)->advanceBalance($partner->fresh())->store())->toBe('0.00');
});

// --- §13 — the Promoter detail dashboard shows the exact locked-requirement figures ---

it('renders the exact locked promoter dashboard figures on the detail screen', function () {
    $actor = \App\Models\User::factory()->create();
    $partner = Partner::factory()->active()->commission('10')->create(['name' => 'Raju']);

    $s1 = confirmedBookingScenario('450000');
    app(AuthorizePartnerForProjectAction::class)->handle($partner, $s1['booking']->project, $actor);
    app(SetBookingPartnerAttribution::class)->handle($s1['booking'], $partner->id, $actor);
    app(\App\Services\Commission\PromoterLedgerService::class)->giveAdvance($partner, \App\Support\Money::of('50000'), 'Initial advance', $actor);
    app(GenerateCommissionCases::class)->handle($s1['booking']->fresh(), $actor);

    $s2 = confirmedBookingScenario('600000');
    app(AuthorizePartnerForProjectAction::class)->handle($partner->fresh(), $s2['booking']->project, $actor);
    app(SetBookingPartnerAttribution::class)->handle($s2['booking'], $partner->id, $actor);
    app(GenerateCommissionCases::class)->handle($s2['booking']->fresh(), $actor);

    $s3 = confirmedBookingScenario('300000');
    app(AuthorizePartnerForProjectAction::class)->handle($partner->fresh(), $s3['booking']->project, $actor);
    app(SetBookingPartnerAttribution::class)->handle($s3['booking'], $partner->id, $actor);
    app(GenerateCommissionCases::class)->handle($s3['booking']->fresh(), $actor);

    $viewer = makeUser(permissions: ['partners.view']);

    \Livewire\Livewire::actingAs($viewer)
        ->test(\App\Livewire\Partners\PartnerShow::class, ['partner' => $partner->fresh()])
        ->assertSee('10%')
        ->assertSee(number_format(1350000, 2))  // Total booking value
        ->assertSee(number_format(135000, 2))   // Gross commission
        ->assertSee(number_format(50000, 2))    // Advance received / adjusted (both equal here)
        ->assertSee(number_format(85000, 2))    // Commission payable
        ->assertViewHas('stats', fn ($stats) => $stats['totalBookings'] === 3
            && $stats['advanceBalance']->store() === '0.00');
});
