<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentPlan;
use App\Models\Receipt;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the M7 tables with the expected columns', function () {
    expect(Schema::hasColumns('payment_plans', ['booking_id', 'name', 'total_amount', 'status', 'allows_variance', 'active_plan_booking_id', 'created_by', 'activated_at', 'approved_by', 'deleted_at']))->toBeTrue();
    expect(Schema::hasColumns('installments', ['payment_plan_id', 'installment_number', 'name', 'due_date', 'amount', 'status', 'waived_at']))->toBeTrue();
    expect(Schema::hasColumns('payments', ['payment_number', 'idempotency_key', 'booking_id', 'payment_mode_id', 'payment_date', 'amount', 'status', 'reference_number', 'cheque_number', 'cheque_status', 'received_by', 'verified_by', 'verified_at', 'reversed_by', 'reversal_reason', 'deleted_at']))->toBeTrue();
    expect(Schema::hasColumns('payment_allocations', ['payment_id', 'installment_id', 'amount', 'allocated_by', 'is_auto']))->toBeTrue();
    expect(Schema::hasColumns('receipts', ['receipt_number', 'payment_id', 'booking_id', 'buyer_id', 'amount', 'payment_mode_label', 'buyer_name_snapshot', 'issued_by', 'issued_at', 'voided_at']))->toBeTrue();

    // financial truth is NOT a column on bookings
    expect(Schema::hasColumn('bookings', 'paid_amount'))->toBeFalse();
});

it('enforces the unique payment number, receipt number and idempotency key', function () {
    Payment::factory()->create(['payment_number' => 'PAY-000099', 'idempotency_key' => 'k1']);

    expect(fn () => Payment::factory()->create(['payment_number' => 'PAY-000099']))->toThrow(QueryException::class);
    expect(fn () => Payment::factory()->create(['idempotency_key' => 'k1']))->toThrow(QueryException::class);

    Receipt::factory()->create(['receipt_number' => 'RCPT-000099']);
    expect(fn () => Receipt::factory()->create(['receipt_number' => 'RCPT-000099']))->toThrow(QueryException::class);
});

it('enforces one allocation row per (payment, installment) pair', function () {
    $payment = Payment::factory()->successful()->create();
    $installment = Installment::factory()->create();
    PaymentAllocation::factory()->create(['payment_id' => $payment->id, 'installment_id' => $installment->id]);

    expect(fn () => PaymentAllocation::factory()->create(['payment_id' => $payment->id, 'installment_id' => $installment->id]))
        ->toThrow(QueryException::class);
});

it('enforces one receipt per payment', function () {
    $payment = Payment::factory()->successful()->create();
    Receipt::factory()->create(['payment_id' => $payment->id]);

    expect(fn () => Receipt::factory()->create(['payment_id' => $payment->id]))->toThrow(QueryException::class);
});

it('restricts hard-deleting a booking that has payments', function () {
    $payment = Payment::factory()->create();

    expect(fn () => Booking::withoutGlobalScopes()->find($payment->booking_id)->forceDelete())
        ->toThrow(QueryException::class);
});

it('cascades installments and allocations when a plan / payment is force-deleted', function () {
    $plan = PaymentPlan::factory()->create();
    $installment = Installment::factory()->create(['payment_plan_id' => $plan->id]);
    $payment = Payment::factory()->successful()->create();
    PaymentAllocation::factory()->create(['payment_id' => $payment->id, 'installment_id' => $installment->id]);

    $payment->forceDelete();
    expect(PaymentAllocation::where('payment_id', $payment->id)->count())->toBe(0);

    $plan->forceDelete();
    expect(Installment::where('payment_plan_id', $plan->id)->count())->toBe(0);
});
