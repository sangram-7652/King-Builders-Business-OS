<?php

declare(strict_types=1);

use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\AgingBucket;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentStatus;
use App\Services\Collections\AgingCalculator;
use App\Services\Payments\PaymentLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function payOn(array $s, string $amount): void
{
    $p = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => $amount,
    ], $s['actor']);
    app(VerifyPaymentAction::class)->handle($p, PaymentStatus::Success, $s['actor']);
}

it('marks a future installment UPCOMING (1)', function () {
    $s = confirmedBookingScenario('500000');
    activePlanFor($s['booking'], $s['actor'], [
        ['type' => 'amount', 'value' => '500000', 'due_date' => now()->addMonth()->toDateString()],
    ]);

    expect($s['booking']->activePaymentPlan->installments->first()->status)->toBe(InstallmentStatus::Upcoming);
});

it('marks a same-day installment DUE (2)', function () {
    $s = confirmedBookingScenario('500000');
    activePlanFor($s['booking'], $s['actor'], [
        ['type' => 'amount', 'value' => '500000', 'due_date' => now()->toDateString()],
    ]);

    expect($s['booking']->activePaymentPlan->installments->first()->status)->toBe(InstallmentStatus::Due);
});

it('marks a past-due unpaid installment OVERDUE (3)', function () {
    $s = confirmedBookingScenario('500000');
    activePlanFor($s['booking'], $s['actor'], [
        ['type' => 'amount', 'value' => '500000', 'due_date' => now()->subDays(10)->toDateString()],
    ]);

    expect($s['booking']->activePaymentPlan->installments->first()->status)->toBe(InstallmentStatus::Overdue);
});

it('marks a partially-paid installment PARTIALLY_PAID / OVERDUE by due date (4)', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking'], $s['actor'], [
        ['type' => 'amount', 'value' => '500000', 'due_date' => now()->addMonth()->toDateString()],
        ['type' => 'amount', 'value' => '500000', 'due_date' => now()->addMonths(2)->toDateString()],
    ]);

    payOn($s, '200000'); // → oldest installment (future) partly paid

    expect($s['booking']->activePaymentPlan->installments->first()->fresh()->status)->toBe(InstallmentStatus::PartiallyPaid);
});

it('excludes paid installments from outstanding aging (5)', function () {
    $s = confirmedBookingScenario('600000');
    activePlanFor($s['booking'], $s['actor'], [
        ['type' => 'amount', 'value' => '300000', 'due_date' => now()->subDays(40)->toDateString()],
        ['type' => 'amount', 'value' => '300000', 'due_date' => now()->subDays(10)->toDateString()],
    ]);

    payOn($s, '300000'); // clears the older overdue installment

    $aging = app(AgingCalculator::class)->agingForBooking($s['booking']->fresh());

    expect($aging[AgingBucket::Days31To60->value]->store())->toBe('0.00')   // 40-day one is paid
        ->and($aging[AgingBucket::Days0To30->value]->store())->toBe('300000.00'); // 10-day one remains
});

it('calculates days overdue from the installment due date (6)', function () {
    $s = confirmedBookingScenario('300000');
    activePlanFor($s['booking'], $s['actor'], [
        ['type' => 'amount', 'value' => '300000', 'due_date' => now()->subDays(45)->toDateString()],
    ]);

    $installment = $s['booking']->activePaymentPlan->installments->first();

    expect(app(AgingCalculator::class)->daysOverdue($installment))->toBe(45);
});

it('places overdue outstanding in the right aging bucket (7)', function () {
    $s = confirmedBookingScenario('1500000');
    activePlanFor($s['booking'], $s['actor'], [
        ['type' => 'amount', 'value' => '100000', 'due_date' => now()->subDays(10)->toDateString()],
        ['type' => 'amount', 'value' => '200000', 'due_date' => now()->subDays(45)->toDateString()],
        ['type' => 'amount', 'value' => '300000', 'due_date' => now()->subDays(75)->toDateString()],
        ['type' => 'amount', 'value' => '400000', 'due_date' => now()->subDays(150)->toDateString()],
        ['type' => 'amount', 'value' => '500000', 'due_date' => now()->subDays(400)->toDateString()],
    ]);

    $aging = app(AgingCalculator::class)->agingForBooking($s['booking']->fresh());

    expect($aging[AgingBucket::Days0To30->value]->store())->toBe('100000.00')
        ->and($aging[AgingBucket::Days31To60->value]->store())->toBe('200000.00')
        ->and($aging[AgingBucket::Days61To90->value]->store())->toBe('300000.00')
        ->and($aging[AgingBucket::Days91To180->value]->store())->toBe('400000.00')
        ->and($aging[AgingBucket::Days180Plus->value]->store())->toBe('500000.00');
});

it('reads outstanding straight from the M7 ledger — no second balance engine', function () {
    $s = overdueCaseScenario('1000000');
    payOn($s, '250000');

    $ledger = app(PaymentLedger::class);
    expect($ledger->bookingOutstanding($s['booking']->fresh())->store())->toBe('750000.00');
});
