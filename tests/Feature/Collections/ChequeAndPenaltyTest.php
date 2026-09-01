<?php

declare(strict_types=1);

use App\Actions\Collections\ApproveBouncePenaltyAction;
use App\Actions\Collections\AssessBouncePenaltyAction;
use App\Actions\Collections\MarkChequeClearedAction;
use App\Actions\Collections\RecordChequeBounceAction;
use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\ChequeStatus;
use App\Enums\PaymentStatus;
use App\Enums\PenaltyStatus;
use App\Exceptions\DomainException;
use App\Models\Payment;
use App\Services\Payments\PaymentLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function chequePayment(array $s, string $amount = '200000'): Payment
{
    return app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id,
        'payment_mode_id' => chequeMode()->id,
        'amount' => $amount,
        'cheque_number' => 'CHQ-'.fake()->numerify('#####'),
        'cheque_date' => now()->toDateString(),
    ], $s['actor']);
}

it('records a cheque payment as PENDING (21)', function () {
    $s = overdueCaseScenario();
    $payment = chequePayment($s);

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->cheque_status)->toBe(ChequeStatus::Pending);
});

it('clears a pending cheque through M7 (22)', function () {
    $s = overdueCaseScenario('1000000');
    $payment = chequePayment($s, '250000');

    $cleared = app(MarkChequeClearedAction::class)->handle($payment, collectionManager());

    expect($cleared->status)->toBe(PaymentStatus::Success)
        ->and($cleared->cheque_status)->toBe(ChequeStatus::Cleared)
        ->and(app(PaymentLedger::class)->bookingPaid($s['booking']->fresh())->store())->toBe('250000.00');
});

it('bounces a pending cheque — payment FAILED, kept in history (23)', function () {
    $s = overdueCaseScenario();
    $payment = chequePayment($s);

    $bounce = app(RecordChequeBounceAction::class)->handle($payment, [
        'bounce_date' => now()->toDateString(),
        'bounce_reason' => 'Insufficient funds',
    ], collectionManager());

    expect($bounce->payment_was_cleared)->toBeFalse()
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Failed)
        ->and($payment->fresh()->cheque_status)->toBe(ChequeStatus::Bounced)
        ->and(Payment::withTrashed()->find($payment->id))->not->toBeNull();
});

it('bounces a cleared cheque — M7 reversal, payment kept as REVERSED (23b)', function () {
    $s = overdueCaseScenario('1000000');
    $payment = chequePayment($s, '300000');
    app(VerifyPaymentAction::class)->handle($payment, PaymentStatus::Success, $s['actor']);
    expect(app(PaymentLedger::class)->bookingPaid($s['booking']->fresh())->store())->toBe('300000.00');

    app(RecordChequeBounceAction::class)->handle($payment->fresh(), [
        'bounce_date' => now()->toDateString(), 'bounce_reason' => 'Stop payment',
    ], collectionManager());

    expect($payment->fresh()->status)->toBe(PaymentStatus::Reversed)
        ->and(app(PaymentLedger::class)->bookingPaid($s['booking']->fresh())->store())->toBe('0.00')
        ->and($payment->fresh()->receipt?->voided_at)->not->toBeNull();
});

it('captures bounce reason, date and bank charges (24 + 25)', function () {
    $s = overdueCaseScenario();
    $payment = chequePayment($s);

    $bounce = app(RecordChequeBounceAction::class)->handle($payment, [
        'bounce_date' => '2026-09-01',
        'bounce_reason' => 'Signature mismatch',
        'bank_charges' => '750',
    ], collectionManager());

    expect($bounce->bounce_reason)->toBe('Signature mismatch')
        ->and($bounce->bounce_date->toDateString())->toBe('2026-09-01')
        ->and($bounce->bank_charges)->toBe('750.00')
        ->and($bounce->handled_by)->not->toBeNull();

    expect(fn () => app(RecordChequeBounceAction::class)->handle(chequePayment($s), [
        'bounce_date' => now()->toDateString(), 'bounce_reason' => '',
    ], collectionManager()))->toThrow(DomainException::class);
});

it('assesses and approves a bounce penalty without touching the booking total (26)', function () {
    $s = overdueCaseScenario('1000000');
    $payment = chequePayment($s);
    $bounce = app(RecordChequeBounceAction::class)->handle($payment, [
        'bounce_date' => now()->toDateString(), 'bounce_reason' => 'NSF',
    ], collectionManager());

    $bookingFinalBefore = $s['booking']->fresh()->final_amount;

    $penalty = app(AssessBouncePenaltyAction::class)->handle($bounce, [
        'penalty_amount' => '1500', 'reason' => 'Bounce charge',
    ], collectionManager());

    expect($penalty->status)->toBe(PenaltyStatus::Assessed);

    $approved = app(ApproveBouncePenaltyAction::class)->handle($penalty, collectionManager());

    expect($approved->status)->toBe(PenaltyStatus::Approved)
        ->and($approved->approved_by)->not->toBeNull()
        ->and($s['booking']->fresh()->final_amount)->toBe($bookingFinalBefore); // unchanged
});

it('rejects an unauthorised penalty approval (27)', function () {
    $s = overdueCaseScenario();
    $payment = chequePayment($s);
    $bounce = app(RecordChequeBounceAction::class)->handle($payment, [
        'bounce_date' => now()->toDateString(), 'bounce_reason' => 'NSF',
    ], collectionManager());
    $penalty = app(AssessBouncePenaltyAction::class)->handle($bounce, ['penalty_amount' => '1000', 'reason' => 'x'], collectionManager());

    expect(fn () => app(ApproveBouncePenaltyAction::class)->handle($penalty, collectionExecutive()))
        ->toThrow(DomainException::class, 'not authorised');
});

it('opens / links a collection case when a cheque bounces', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking'], $s['actor']);
    expect($s['booking']->collectionCase)->toBeNull();

    $payment = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => chequeMode()->id, 'amount' => '100000',
        'cheque_number' => 'C1', 'cheque_date' => now()->toDateString(),
    ], $s['actor']);
    app(RecordChequeBounceAction::class)->handle($payment, [
        'bounce_date' => now()->toDateString(), 'bounce_reason' => 'NSF',
    ], collectionManager());

    expect($s['booking']->fresh()->collectionCase)->not->toBeNull();
});
