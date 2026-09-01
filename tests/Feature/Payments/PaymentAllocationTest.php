<?php

declare(strict_types=1);

use App\Actions\Payments\AllocatePaymentAction;
use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainException;
use App\Models\Payment;
use App\Services\Payments\PaymentLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A recorded-but-unverified payment, so allocation can be tested in isolation. */
function successfulUnallocatedPayment(array $s, string $amount): Payment
{
    $payment = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => $amount,
    ], $s['actor']);

    // verify → auto-allocates; undo the auto allocations for a clean slate
    $payment = app(VerifyPaymentAction::class)->handle($payment, PaymentStatus::Success, $s['actor']);
    $payment->allocations()->delete();

    return $payment->fresh();
}

function twoInstallmentPlan(array $s): void
{
    activePlanFor($s['booking'], $s['actor'], [
        ['type' => 'amount', 'value' => '300000', 'due_date' => now()->addMonth()->toDateString()],
        ['type' => 'amount', 'value' => '700000', 'due_date' => now()->addMonths(2)->toDateString()],
    ]);
}

it('allocates a payment to an installment (21)', function () {
    $s = confirmedBookingScenario('1000000');
    twoInstallmentPlan($s);
    $payment = successfulUnallocatedPayment($s, '300000');
    $inst = $s['booking']->activePaymentPlan->installments()->first();

    app(AllocatePaymentAction::class)->handle($payment, [['installment_id' => $inst->id, 'amount' => '300000']], financeManager());

    expect(app(PaymentLedger::class)->installmentPaid($inst->fresh())->store())->toBe('300000.00');
});

it('allocates one payment across multiple installments (22)', function () {
    $s = confirmedBookingScenario('1000000');
    twoInstallmentPlan($s);
    $payment = successfulUnallocatedPayment($s, '500000');
    [$i1, $i2] = $s['booking']->activePaymentPlan->installments->all();

    app(AllocatePaymentAction::class)->handle($payment, [
        ['installment_id' => $i1->id, 'amount' => '300000'],
        ['installment_id' => $i2->id, 'amount' => '200000'],
    ], financeManager());

    $ledger = app(PaymentLedger::class);
    expect($ledger->installmentPaid($i1->fresh())->store())->toBe('300000.00')
        ->and($ledger->installmentPaid($i2->fresh())->store())->toBe('200000.00')
        ->and($ledger->paymentUnallocated($payment->fresh())->store())->toBe('0.00');
});

it('supports partial allocation, leaving the rest unallocated (23)', function () {
    $s = confirmedBookingScenario('1000000');
    twoInstallmentPlan($s);
    $payment = successfulUnallocatedPayment($s, '300000');
    $i1 = $s['booking']->activePaymentPlan->installments->first();

    app(AllocatePaymentAction::class)->handle($payment, [['installment_id' => $i1->id, 'amount' => '100000']], financeManager());

    expect(app(PaymentLedger::class)->paymentUnallocated($payment->fresh())->store())->toBe('200000.00');
});

it('rejects an allocation above the installment outstanding (24)', function () {
    $s = confirmedBookingScenario('1000000');
    twoInstallmentPlan($s);
    $payment = successfulUnallocatedPayment($s, '500000');
    $i1 = $s['booking']->activePaymentPlan->installments->first(); // 300k

    expect(fn () => app(AllocatePaymentAction::class)->handle($payment, [['installment_id' => $i1->id, 'amount' => '400000']], financeManager()))
        ->toThrow(DomainException::class);
});

it('rejects allocations that exceed the payment amount (25)', function () {
    $s = confirmedBookingScenario('1000000');
    twoInstallmentPlan($s);
    $payment = successfulUnallocatedPayment($s, '300000');
    [$i1, $i2] = $s['booking']->activePaymentPlan->installments->all();

    expect(fn () => app(AllocatePaymentAction::class)->handle($payment, [
        ['installment_id' => $i1->id, 'amount' => '300000'],
        ['installment_id' => $i2->id, 'amount' => '1'],
    ], financeManager()))->toThrow(DomainException::class);
});

it('defaults to oldest-installment-first allocation (26)', function () {
    $s = confirmedBookingScenario('700000');
    activePlanFor($s['booking'], $s['actor'], [
        ['type' => 'amount', 'value' => '300000', 'due_date' => now()->subMonth()->toDateString()],
        ['type' => 'amount', 'value' => '400000', 'due_date' => now()->addMonth()->toDateString()],
    ]);

    // Payment of 500,000 → 300,000 to inst1, 200,000 to inst2
    $payment = app(RecordPaymentAction::class)->handle(['booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '500000'], $s['actor']);
    $payment = app(VerifyPaymentAction::class)->handle($payment, PaymentStatus::Success, $s['actor']);

    [$i1, $i2] = $s['booking']->activePaymentPlan->installments->all();
    $ledger = app(PaymentLedger::class);

    expect($ledger->installmentPaid($i1->fresh())->store())->toBe('300000.00')
        ->and($ledger->installmentPaid($i2->fresh())->store())->toBe('200000.00')
        ->and($ledger->installmentOutstanding($i2->fresh())->store())->toBe('200000.00');
});

it('never hides an overpayment — leftover stays unallocated (27)', function () {
    $s = confirmedBookingScenario('100000');
    activePlanFor($s['booking'], $s['actor'], [
        ['type' => 'amount', 'value' => '100000', 'due_date' => now()->addMonth()->toDateString()],
    ]);

    // Pay 120,000 against a 100,000 plan → 100,000 allocated, 20,000 unallocated
    $payment = app(RecordPaymentAction::class)->handle(['booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '120000'], $s['actor']);
    $payment = app(VerifyPaymentAction::class)->handle($payment, PaymentStatus::Success, $s['actor']);

    $ledger = app(PaymentLedger::class);
    expect($ledger->paymentAllocated($payment->fresh())->store())->toBe('100000.00')
        ->and($ledger->paymentUnallocated($payment->fresh())->store())->toBe('20000.00')
        ->and($ledger->bookingUnallocated($s['booking']->fresh())->store())->toBe('20000.00');
});

it('protects against double allocation of the same (payment, installment) pair (28)', function () {
    $s = confirmedBookingScenario('1000000');
    twoInstallmentPlan($s);
    $payment = successfulUnallocatedPayment($s, '600000');
    $i1 = $s['booking']->activePaymentPlan->installments->first();

    app(AllocatePaymentAction::class)->handle($payment, [['installment_id' => $i1->id, 'amount' => '300000']], financeManager());
    // Re-submitting the same pair is a no-op, not a double count.
    app(AllocatePaymentAction::class)->handle($payment, [['installment_id' => $i1->id, 'amount' => '300000']], financeManager());

    expect(app(PaymentLedger::class)->installmentPaid($i1->fresh())->store())->toBe('300000.00')
        ->and($payment->fresh()->allocations()->where('installment_id', $i1->id)->count())->toBe(1);
});
