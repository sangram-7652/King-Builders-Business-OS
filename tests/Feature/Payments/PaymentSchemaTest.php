<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Receipt;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the M7 tables with the expected columns', function () {
    expect(Schema::hasColumns('payments', ['payment_number', 'idempotency_key', 'booking_id', 'payment_mode_id', 'payment_date', 'amount', 'status', 'reference_number', 'cheque_number', 'cheque_status', 'received_by', 'verified_by', 'verified_at', 'reversed_by', 'reversal_reason', 'deleted_at']))->toBeTrue();
    expect(Schema::hasColumns('receipts', ['receipt_number', 'payment_id', 'booking_id', 'buyer_id', 'amount', 'payment_mode_label', 'buyer_name_snapshot', 'issued_by', 'issued_at', 'voided_at']))->toBeTrue();

    // financial truth is NOT a column on bookings
    expect(Schema::hasColumn('bookings', 'paid_amount'))->toBeFalse();

    // the payment plan / installment / allocation modules have been decommissioned
    expect(Schema::hasTable('payment_plans'))->toBeFalse();
    expect(Schema::hasTable('installments'))->toBeFalse();
    expect(Schema::hasTable('payment_allocations'))->toBeFalse();
});

it('enforces the unique payment number, receipt number and idempotency key', function () {
    Payment::factory()->create(['payment_number' => 'PAY-000099', 'idempotency_key' => 'k1']);

    expect(fn () => Payment::factory()->create(['payment_number' => 'PAY-000099']))->toThrow(QueryException::class);
    expect(fn () => Payment::factory()->create(['idempotency_key' => 'k1']))->toThrow(QueryException::class);

    Receipt::factory()->create(['receipt_number' => 'RCPT-000099']);
    expect(fn () => Receipt::factory()->create(['receipt_number' => 'RCPT-000099']))->toThrow(QueryException::class);
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
