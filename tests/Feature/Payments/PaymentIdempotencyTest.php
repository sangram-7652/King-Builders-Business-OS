<?php

declare(strict_types=1);

use App\Actions\Payments\AllocatePaymentAction;
use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Receipt;
use App\Services\Payments\PaymentLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('a duplicate "record payment" with the same idempotency key does not create two payments', function () {
    $s = confirmedBookingScenario();
    $data = [
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '250000',
        'idempotency_key' => 'req-abc-123',
    ];

    $a = app(RecordPaymentAction::class)->handle($data, $s['actor']);
    $b = app(RecordPaymentAction::class)->handle($data, $s['actor']);

    expect($b->id)->toBe($a->id)
        ->and(Payment::count())->toBe(1);
});

it('duplicate verification does not double-count money or issue a second receipt (43)', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking'], $s['actor']);
    $payment = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '250000',
    ], $s['actor']);

    $first = app(VerifyPaymentAction::class)->handle($payment, PaymentStatus::Success, $s['actor']);
    $second = app(VerifyPaymentAction::class)->handle($payment->fresh(), PaymentStatus::Success, $s['actor']);

    expect($second->id)->toBe($first->id)
        ->and(Receipt::where('payment_id', $payment->id)->count())->toBe(1)
        ->and($payment->fresh()->allocations()->count())->toBe(1)
        ->and(app(PaymentLedger::class)->bookingPaid($s['booking']->fresh())->store())->toBe('250000.00');
});

it('duplicate allocation does not double-count (44)', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking'], $s['actor'], [
        ['type' => 'amount', 'value' => '400000', 'due_date' => now()->addMonth()->toDateString()],
        ['type' => 'amount', 'value' => '600000', 'due_date' => now()->addMonths(2)->toDateString()],
    ]);

    $payment = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '400000',
    ], $s['actor']);
    $payment = app(VerifyPaymentAction::class)->handle($payment, PaymentStatus::Success, $s['actor']); // auto-allocates 400k to inst1

    // Re-run auto allocation — nothing left unallocated, so no-op
    app(AllocatePaymentAction::class)->handle($payment->fresh(), null, $s['actor']);

    $i1 = $s['booking']->activePaymentPlan->installments->first();
    expect(app(PaymentLedger::class)->installmentPaid($i1->fresh())->store())->toBe('400000.00')
        ->and($payment->fresh()->allocations()->count())->toBe(1)
        ->and(app(PaymentLedger::class)->paymentUnallocated($payment->fresh())->store())->toBe('0.00');
});
