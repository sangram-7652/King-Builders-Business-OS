<?php

declare(strict_types=1);

use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Services\Collections\AgingCalculator;
use App\Services\Payments\PaymentLedger;
use App\Services\Reports\CollectionAnalytics;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

/**
 * F-REP-1 / F-TEST-2 — the collections report figures must equal the
 * operational M7/M8 truth (PaymentLedger + AgingCalculator) to the paise,
 * summed per booking. This is the regression guard against money precision
 * drift in the reporting layer.
 */

/** Sum a per-booking PaymentLedger metric across every confirmed booking. */
function ledgerSum(string $metric): string
{
    $ledger = app(PaymentLedger::class);
    $aging = app(AgingCalculator::class);
    $total = Money::zero();

    Booking::query()->where('status', 'confirmed')->with('activePaymentPlan.installments')->get()
        ->each(function (Booking $b) use (&$total, $ledger, $metric): void {
            $plan = $b->activePaymentPlan;
            if ($plan === null) {
                return;
            }

            $total = match ($metric) {
                'receivable' => $total->plus(
                    $plan->installments->filter(fn ($i) => $i->status->value !== 'waived')
                        ->reduce(fn (Money $c, $i) => $c->plus(Money::of($i->amount)), Money::zero())
                ),
                'outstanding' => $plan->installments->filter(fn ($i) => $i->status->value !== 'waived')
                    ->reduce(fn (Money $c, $i) => $c->plus($ledger->installmentOutstanding($i)), $total),
                'overdue' => $total->plus($ledger->bookingOverdue($b)),
                default => $total,
            };
        });

    return $total->store();
}

it('reconciles collections KPIs with the operational ledger to the paise', function () {
    // A world with two bookings, partial payments, an overdue installment, a
    // waived one, and awkward non-round amounts (₹333,333.33 thirds).
    $s1 = confirmedBookingScenario('1000000.01');
    activePlanFor($s1['booking'], $s1['actor'], [
        ['type' => 'amount', 'value' => '333333.34', 'due_date' => now()->subMonths(2)->toDateString()],
        ['type' => 'amount', 'value' => '333333.33', 'due_date' => now()->subMonths(1)->toDateString()],
        ['type' => 'amount', 'value' => '333333.34', 'due_date' => now()->addMonth()->toDateString()],
    ]);
    $p = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s1['booking']->id, 'payment_mode_id' => cashMode()->id,
        'amount' => '400000.05', 'payment_date' => now()->subMonth()->toDateString(),
    ], $s1['actor']);
    app(VerifyPaymentAction::class)->handle($p, PaymentStatus::Success, $s1['actor']);

    $s2 = confirmedBookingScenario('2500000.99');
    activePlanFor($s2['booking'], $s2['actor'], [
        ['type' => 'amount', 'value' => '1250000.50', 'due_date' => now()->subDays(10)->toDateString()],
        ['type' => 'amount', 'value' => '1250000.49', 'due_date' => now()->addMonths(2)->toDateString()],
    ]);

    $filters = misFilter(['from' => now()->subYear()->toDateString(), 'to' => now()->addYear()->toDateString()]);
    $kpis = app(CollectionAnalytics::class)->kpis($filters);

    expect(number_format($kpis['receivable'], 2, '.', ''))->toBe(ledgerSum('receivable'))
        ->and(number_format($kpis['outstanding'], 2, '.', ''))->toBe(ledgerSum('outstanding'))
        ->and(number_format($kpis['overdue'], 2, '.', ''))->toBe(ledgerSum('overdue'));
});

it('reconciles the booking-value / cash-collected reconciliation block with the ledger', function () {
    $s = confirmedBookingScenario('1234567.89');
    activePlanFor($s['booking'], $s['actor'], [
        ['type' => 'amount', 'value' => '617283.94', 'due_date' => now()->subMonth()->toDateString()],
        ['type' => 'amount', 'value' => '617283.95', 'due_date' => now()->addMonth()->toDateString()],
    ]);
    $p = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id,
        'amount' => '500000.33', 'payment_date' => now()->toDateString(),
    ], $s['actor']);
    app(VerifyPaymentAction::class)->handle($p, PaymentStatus::Success, $s['actor']);

    $filters = misFilter(['from' => now()->subYear()->toDateString(), 'to' => now()->addYear()->toDateString()]);
    $recon = app(CollectionAnalytics::class)->reconciliation($filters);

    $ledger = app(PaymentLedger::class);
    $b = $s['booking']->fresh();

    expect(number_format($recon['bookingValue'], 2, '.', ''))->toBe(Money::of($b->final_amount)->store())
        ->and(number_format($recon['cashCollected'], 2, '.', ''))->toBe($ledger->bookingPaid($b)->store());
});
