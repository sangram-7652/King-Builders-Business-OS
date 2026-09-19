<?php

declare(strict_types=1);

use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\ReversePaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainException;
use App\Models\Payment;
use App\Services\Payments\PaymentLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function successfulPayment(array $s, string $amount): Payment
{
    $p = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => $amount,
    ], $s['actor']);

    return app(VerifyPaymentAction::class)->handle($p, PaymentStatus::Success, $s['actor']);
}

it('reverses a successful payment without deleting it (33)', function () {
    $s = confirmedBookingScenario('1000000');
    $payment = successfulPayment($s, '250000');
    $reverser = financeManager();

    $reversed = app(ReversePaymentAction::class)->handle($payment, 'buyer chargeback', $reverser);

    expect($reversed->status)->toBe(PaymentStatus::Reversed)
        ->and($reversed->reversed_by)->toBe($reverser->id)
        ->and($reversed->reversed_at)->not->toBeNull()
        ->and($reversed->reversal_reason)->toBe('buyer chargeback')
        ->and(Payment::withTrashed()->find($payment->id))->not->toBeNull()
        // no longer counts towards the balance
        ->and(app(PaymentLedger::class)->bookingPaid($s['booking']->fresh())->store())->toBe('0.00')
        // receipt voided, not deleted
        ->and($reversed->receipt->voided_at)->not->toBeNull();
});

it('requires a reason to reverse (35)', function () {
    $s = confirmedBookingScenario();
    $payment = successfulPayment($s, '100000');

    expect(fn () => app(ReversePaymentAction::class)->handle($payment, '   ', financeManager()))
        ->toThrow(DomainException::class, 'reason');
});

it('rejects an unauthorised reversal (36)', function () {
    $s = confirmedBookingScenario();
    $payment = successfulPayment($s, '100000');

    expect(fn () => app(ReversePaymentAction::class)->handle($payment, 'nope', cashier()))
        ->toThrow(DomainException::class, 'authorised');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Success);
});

it('rejects a double reversal (37)', function () {
    $s = confirmedBookingScenario('1000000');
    $payment = successfulPayment($s, '250000');
    $reverser = financeManager();

    app(ReversePaymentAction::class)->handle($payment, 'first', $reverser);
    $second = app(ReversePaymentAction::class)->handle($payment->fresh(), 'second', $reverser);

    expect($second->status)->toBe(PaymentStatus::Reversed)
        ->and($second->reversal_reason)->toBe('first') // unchanged — idempotent no-op
        ->and(app(PaymentLedger::class)->bookingPaid($s['booking']->fresh())->store())->toBe('0.00');
});
