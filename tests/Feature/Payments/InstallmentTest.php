<?php

declare(strict_types=1);

use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Payments\PaymentLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function payAndVerify(array $s, string $amount, ?string $date = null): Payment
{
    $payment = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id,
        'payment_mode_id' => cashMode()->id,
        'amount' => $amount,
        'payment_date' => $date ?? now()->toDateString(),
    ], $s['actor']);

    return app(VerifyPaymentAction::class)->handle($payment, PaymentStatus::Success, $s['actor']);
}

it('derives installment statuses from due date + ledger (9)', function () {
    $s = confirmedBookingScenario('400000');
    $plan = activePlanFor($s['booking'], $s['actor'], [
        ['type' => 'amount', 'value' => '100000', 'due_date' => now()->subMonth()->toDateString()],  // overdue
        ['type' => 'amount', 'value' => '100000', 'due_date' => now()->toDateString()],               // due
        ['type' => 'amount', 'value' => '100000', 'due_date' => now()->addMonth()->toDateString()],   // upcoming
        ['type' => 'amount', 'value' => '100000', 'due_date' => now()->addMonths(2)->toDateString()], // upcoming
    ]);

    expect($plan->installments->pluck('status')->map->value->all())
        ->toBe(['overdue', 'due', 'upcoming', 'upcoming']);
});

it('marks an installment PARTIALLY_PAID on a partial payment (10)', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking'], $s['actor'], [
        ['type' => 'amount', 'value' => '500000', 'due_date' => now()->addMonth()->toDateString()],
        ['type' => 'amount', 'value' => '500000', 'due_date' => now()->addMonths(2)->toDateString()],
    ]);

    payAndVerify($s, '200000');

    $ledger = app(PaymentLedger::class);
    $inst = $s['booking']->activePaymentPlan->installments()->orderBy('installment_number')->first();

    expect($inst->fresh()->status)->toBe(InstallmentStatus::PartiallyPaid)
        ->and($ledger->installmentPaid($inst)->store())->toBe('200000.00')
        ->and($ledger->installmentOutstanding($inst)->store())->toBe('300000.00');
});

it('marks an installment PAID on full payment across two payments (11)', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking'], $s['actor'], [
        ['type' => 'amount', 'value' => '500000', 'due_date' => now()->addMonth()->toDateString()],
        ['type' => 'amount', 'value' => '500000', 'due_date' => now()->addMonths(2)->toDateString()],
    ]);

    payAndVerify($s, '200000');
    payAndVerify($s, '300000');

    $inst = $s['booking']->activePaymentPlan->installments()->orderBy('installment_number')->first();
    expect($inst->fresh()->status)->toBe(InstallmentStatus::Paid)
        ->and(app(PaymentLedger::class)->installmentOutstanding($inst->fresh())->store())->toBe('0.00');
});

it('marks an unpaid past-due installment OVERDUE (12)', function () {
    $s = confirmedBookingScenario('300000');
    $plan = activePlanFor($s['booking'], $s['actor'], [
        ['type' => 'amount', 'value' => '300000', 'due_date' => now()->subWeek()->toDateString()],
    ]);

    expect($plan->installments->first()->status)->toBe(InstallmentStatus::Overdue);
});

it('computes booking-level outstanding + overdue from the ledger (13)', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking'], $s['actor'], [
        ['type' => 'amount', 'value' => '400000', 'due_date' => now()->subMonth()->toDateString()],
        ['type' => 'amount', 'value' => '600000', 'due_date' => now()->addMonth()->toDateString()],
    ]);

    payAndVerify($s, '250000'); // allocates to the overdue one first

    $summary = app(PaymentLedger::class)->summary($s['booking']->fresh());

    expect($summary->paid->store())->toBe('250000.00')
        ->and($summary->outstanding->store())->toBe('750000.00')
        ->and($summary->overdue->store())->toBe('150000.00'); // 400k due − 250k paid
});
