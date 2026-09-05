<?php

declare(strict_types=1);

use App\Actions\Commission\ApproveCommissionCase;
use App\Actions\Commission\RecordCommissionPayout;
use App\Actions\Commission\VoidCommissionPayout;
use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\CommissionCaseStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

function approvedCommissionCase(): array
{
    ['actor' => $actor, 'booking' => $booking, 'case' => $case] = pendingCase();
    app(ApproveCommissionCase::class)->handle($case, $actor);

    return ['actor' => $actor, 'booking' => $booking, 'case' => $case->fresh()];
}

/** Record + verify a SUCCESS payment on the booking (no plan needed — the
 *  collection gate reads PaymentLedger::bookingPaid, which sums SUCCESS payments). */
function payTowards(array $ctx, string $amount): void
{
    $p = app(RecordPaymentAction::class)->handle([
        'booking_id' => $ctx['booking']->id, 'payment_mode_id' => cashMode()->id,
        'amount' => $amount, 'payment_date' => now()->toDateString(),
    ], $ctx['actor']);
    app(VerifyPaymentAction::class)->handle($p, PaymentStatus::Success, $ctx['actor']);
}

/*
| F-M14-2 — a voided payout must not count toward the overpayment guard.
*/

it('does not count a voided payout against the commission ceiling', function () {
    ['actor' => $actor, 'case' => $case] = approvedCommissionCase(); // ₹100,000 commission

    $first = app(RecordCommissionPayout::class)->handle($case->fresh(), [
        'amount' => '100000', 'method' => 'bank_transfer', 'paid_on' => now()->toDateString(),
    ], $actor);
    expect($case->fresh()->status)->toBe(CommissionCaseStatus::Paid);

    app(VoidCommissionPayout::class)->handle($first->fresh(), $actor, 'wrong account');
    expect($case->fresh()->status)->toBe(CommissionCaseStatus::Approved)
        ->and((string) $case->fresh()->paid_amount)->toBe('0.00');

    // The full ceiling is available again — the voided ₹100,000 is not "paid".
    app(RecordCommissionPayout::class)->handle($case->fresh(), [
        'amount' => '100000', 'method' => 'bank_transfer', 'paid_on' => now()->toDateString(),
    ], $actor);

    expect($case->fresh()->status)->toBe(CommissionCaseStatus::Paid)
        ->and((string) $case->fresh()->paid_amount)->toBe('100000.00');
});

/*
| F-M14-3 — the configured minimum-collected gate is enforced at payout time.
*/

it('blocks a payout at 0% collection when the minimum is raised after approval', function () {
    ['actor' => $actor, 'case' => $case] = approvedCommissionCase(); // generated + approved at the default 0%
    config()->set('commission.eligibility.min_collected_percent', 50); // ops raises the bar

    expect(fn () => app(RecordCommissionPayout::class)->handle($case->fresh(), [
        'amount' => '10000', 'method' => 'cash', 'paid_on' => now()->toDateString(),
    ], $actor))->toThrow(DomainException::class, 'payout blocked');
});

it('blocks a payout below the configured threshold and allows it once reached', function () {
    $ctx = approvedCommissionCase(); // booking value ₹50,00,000 → 50% = ₹25,00,000
    config()->set('commission.eligibility.min_collected_percent', 50);

    payTowards($ctx, '2000000'); // 40% — still short
    expect(fn () => app(RecordCommissionPayout::class)->handle($ctx['case']->fresh(), [
        'amount' => '10000', 'method' => 'cash', 'paid_on' => now()->toDateString(),
    ], $ctx['actor']))->toThrow(DomainException::class, 'payout blocked');

    payTowards($ctx, '600000'); // now ₹26,00,000 collected → over 50%
    $payout = app(RecordCommissionPayout::class)->handle($ctx['case']->fresh(), [
        'amount' => '10000', 'method' => 'cash', 'paid_on' => now()->toDateString(),
    ], $ctx['actor']);

    expect($payout->exists)->toBeTrue();
});

it('does not gate payouts when no minimum is configured (default behaviour preserved)', function () {
    // default config value is 0
    ['actor' => $actor, 'case' => $case] = approvedCommissionCase();

    $payout = app(RecordCommissionPayout::class)->handle($case->fresh(), [
        'amount' => '10000', 'method' => 'cash', 'paid_on' => now()->toDateString(),
    ], $actor);

    expect($payout->exists)->toBeTrue();
});
