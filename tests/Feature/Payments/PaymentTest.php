<?php

declare(strict_types=1);

use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\ChequeStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Masters\PaymentMode;
use App\Models\Payment;
use App\Services\Payments\PaymentLedger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records a payment as PENDING against a confirmed booking (14)', function () {
    $s = confirmedBookingScenario();

    $payment = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id,
        'payment_mode_id' => cashMode()->id,
        'amount' => '250000',
        'payment_date' => now()->toDateString(),
        'reference_number' => 'TXN-1',
    ], $s['actor']);

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->payment_number)->toBe('PAY-000001')
        ->and($payment->received_by)->toBe($s['actor']->id)
        ->and($payment->amount)->toBe('250000.00');

    // a pending payment does not move the ledger
    expect(app(PaymentLedger::class)->bookingPaid($s['booking']->fresh())->store())->toBe('0.00');
});

it('generates unique, sequential payment numbers (15)', function () {
    $s = confirmedBookingScenario();

    $a = app(RecordPaymentAction::class)->handle(['booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '1'], $s['actor']);
    $b = app(RecordPaymentAction::class)->handle(['booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '1'], $s['actor']);

    expect($a->payment_number)->toBe('PAY-000001')
        ->and($b->payment_number)->toBe('PAY-000002');

    expect(fn () => Payment::factory()->create(['payment_number' => 'PAY-000001']))
        ->toThrow(QueryException::class);
});

it('rejects a payment against a non-confirmed booking and a non-positive amount (16)', function () {
    $s = confirmedBookingScenario();
    $draft = Booking::factory()->create(['status' => 'draft']);

    expect(fn () => app(RecordPaymentAction::class)->handle(['booking_id' => $draft->id, 'payment_mode_id' => cashMode()->id, 'amount' => '100'], $s['actor']))
        ->toThrow(DomainException::class);

    expect(fn () => app(RecordPaymentAction::class)->handle(['booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '0'], $s['actor']))
        ->toThrow(DomainException::class);
});

it('verifies a payment SUCCESS — moves the balance and issues a receipt (17)', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking'], $s['actor']);

    $payment = app(RecordPaymentAction::class)->handle(['booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '250000'], $s['actor']);
    $verified = app(VerifyPaymentAction::class)->handle($payment, PaymentStatus::Success, $s['actor']);

    expect($verified->status)->toBe(PaymentStatus::Success)
        ->and($verified->verified_by)->toBe($s['actor']->id)
        ->and($verified->verified_at)->not->toBeNull()
        ->and($verified->receipt)->not->toBeNull()
        ->and(app(PaymentLedger::class)->bookingPaid($s['booking']->fresh())->store())->toBe('250000.00');
});

it('verifies a payment FAILED — no balance movement, no receipt (18)', function () {
    $s = confirmedBookingScenario();

    $payment = app(RecordPaymentAction::class)->handle(['booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '250000'], $s['actor']);
    $failed = app(VerifyPaymentAction::class)->handle($payment, PaymentStatus::Failed, $s['actor']);

    expect($failed->status)->toBe(PaymentStatus::Failed)
        ->and($failed->receipt)->toBeNull()
        ->and(app(PaymentLedger::class)->bookingPaid($s['booking']->fresh())->store())->toBe('0.00');
});

it('supports configurable payment methods from the master (19)', function () {
    $s = confirmedBookingScenario();
    $upi = PaymentMode::query()->firstOrCreate(['code' => 'UPI'], ['name' => 'UPI', 'is_active' => true, 'requires_reference' => true]);
    $card = PaymentMode::factory()->create(['code' => 'CARD', 'name' => 'Card']);

    foreach ([$upi, $card] as $mode) {
        $p = app(RecordPaymentAction::class)->handle([
            'booking_id' => $s['booking']->id, 'payment_mode_id' => $mode->id, 'amount' => '1000',
        ], $s['actor']);
        expect($p->payment_mode_id)->toBe($mode->id);
    }
});

it('captures cheque metadata and runs the cheque lifecycle (20)', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking'], $s['actor']);

    // missing cheque details are rejected
    expect(fn () => app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => chequeMode()->id, 'amount' => '250000',
    ], $s['actor']))->toThrow(DomainException::class);

    $payment = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id,
        'payment_mode_id' => chequeMode()->id,
        'amount' => '250000',
        'cheque_number' => '000123',
        'cheque_bank_name' => 'HDFC',
        'cheque_date' => now()->toDateString(),
    ], $s['actor']);

    expect($payment->cheque_status)->toBe(ChequeStatus::Pending)
        ->and($payment->cheque_number)->toBe('000123');

    $cleared = app(VerifyPaymentAction::class)->handle($payment, PaymentStatus::Success, $s['actor']);
    expect($cleared->cheque_status)->toBe(ChequeStatus::Cleared);

    // a bounced cheque
    $bouncePayment = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => chequeMode()->id, 'amount' => '100000',
        'cheque_number' => '000124', 'cheque_date' => now()->toDateString(),
    ], $s['actor']);
    $bounced = app(VerifyPaymentAction::class)->handle($bouncePayment, PaymentStatus::Failed, $s['actor']);
    expect($bounced->cheque_status)->toBe(ChequeStatus::Bounced);
});
