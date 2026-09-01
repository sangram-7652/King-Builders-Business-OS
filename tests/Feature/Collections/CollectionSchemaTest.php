<?php

declare(strict_types=1);

use App\Models\Booking;
use App\Models\BouncePenalty;
use App\Models\ChequeBounce;
use App\Models\CollectionCase;
use App\Models\CollectionFollowUp;
use App\Models\CollectionReminder;
use App\Models\Payment;
use App\Models\PaymentPromise;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the M8 tables with the expected columns and no money on the case', function () {
    expect(Schema::hasColumns('collection_cases', ['booking_id', 'assigned_to', 'status', 'priority', 'opened_at', 'next_follow_up_at', 'resolved_at']))->toBeTrue();
    expect(Schema::hasColumns('collection_follow_ups', ['collection_case_id', 'booking_id', 'installment_id', 'assigned_to', 'follow_up_at', 'outcome', 'completed_at', 'next_follow_up_at', 'idempotency_key']))->toBeTrue();
    expect(Schema::hasColumns('payment_promises', ['collection_case_id', 'booking_id', 'installment_id', 'promised_amount', 'outstanding_at_creation', 'promise_date', 'status', 'fulfilled_by_payment_id', 'idempotency_key']))->toBeTrue();
    expect(Schema::hasColumns('cheque_bounces', ['payment_id', 'booking_id', 'bounce_date', 'bounce_reason', 'bank_charges', 'payment_was_cleared', 'handled_by']))->toBeTrue();
    expect(Schema::hasColumns('bounce_penalties', ['cheque_bounce_id', 'booking_id', 'penalty_amount', 'reason', 'status', 'assessed_by', 'approved_by', 'approved_at']))->toBeTrue();
    expect(Schema::hasColumns('collection_activities', ['collection_case_id', 'booking_id', 'type', 'description', 'properties', 'causer_id']))->toBeTrue();
    expect(Schema::hasColumns('collection_reminders', ['booking_id', 'type', 'reference_type', 'reference_id', 'remind_on', 'status']))->toBeTrue();

    // the case carries NO balance columns — money lives in M7
    expect(Schema::hasColumn('collection_cases', 'outstanding'))->toBeFalse()
        ->and(Schema::hasColumn('collection_cases', 'paid_amount'))->toBeFalse();
});

it('enforces one collection case per booking', function () {
    $booking = Booking::factory()->confirmed()->create();
    CollectionCase::factory()->create(['booking_id' => $booking->id]);

    expect(fn () => CollectionCase::factory()->create(['booking_id' => $booking->id]))
        ->toThrow(QueryException::class);
});

it('enforces one cheque bounce per payment and unique idempotency keys', function () {
    ChequeBounce::factory()->create(['payment_id' => Payment::factory()->create()->id]);

    CollectionFollowUp::factory()->create(['idempotency_key' => 'k1']);
    expect(fn () => CollectionFollowUp::factory()->create(['idempotency_key' => 'k1']))->toThrow(QueryException::class);

    PaymentPromise::factory()->create(['idempotency_key' => 'p1']);
    expect(fn () => PaymentPromise::factory()->create(['idempotency_key' => 'p1']))->toThrow(QueryException::class);
});

it('dedupes reminders on the composite key', function () {
    $booking = Booking::factory()->confirmed()->create();
    $on = now()->toDateString();

    CollectionReminder::factory()->create(['booking_id' => $booking->id, 'type' => 'overdue', 'reference_type' => 'installment', 'reference_id' => 5, 'remind_on' => $on]);

    expect(fn () => CollectionReminder::factory()->create([
        'booking_id' => $booking->id, 'type' => 'overdue', 'reference_type' => 'installment', 'reference_id' => 5, 'remind_on' => $on,
    ]))->toThrow(QueryException::class);
});

it('cascades follow-ups / promises / activities when a case is deleted', function () {
    $case = CollectionCase::factory()->create();
    CollectionFollowUp::factory()->create(['collection_case_id' => $case->id, 'booking_id' => $case->booking_id]);
    PaymentPromise::factory()->create(['collection_case_id' => $case->id, 'booking_id' => $case->booking_id]);

    $case->delete();

    expect(CollectionFollowUp::where('collection_case_id', $case->id)->count())->toBe(0)
        ->and(PaymentPromise::where('collection_case_id', $case->id)->count())->toBe(0);
});

it('a bounce penalty is never posted to the booking financial total', function () {
    $booking = Booking::factory()->confirmed()->create(['final_amount' => 1000000]);
    $bounce = ChequeBounce::factory()->create(['booking_id' => $booking->id]);
    BouncePenalty::factory()->approved()->create(['cheque_bounce_id' => $bounce->id, 'booking_id' => $booking->id, 'penalty_amount' => 5000]);

    expect($booking->fresh()->final_amount)->toBe('1000000.00');
});
