<?php

declare(strict_types=1);

use App\Actions\Collections\CancelPaymentPromiseAction;
use App\Actions\Collections\CreatePaymentPromiseAction;
use App\Actions\Collections\EvaluatePaymentPromisesAction;
use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\PaymentStatus;
use App\Enums\PromiseStatus;
use App\Exceptions\DomainException;
use App\Models\PaymentPromise;
use App\Services\Payments\PaymentLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function promiseFor(array $s, string $amount, string $date): PaymentPromise
{
    return app(CreatePaymentPromiseAction::class)->handle([
        'booking_id' => $s['booking']->id,
        'promised_amount' => $amount,
        'promise_date' => $date,
    ], $s['actor']);
}

it('creates a promise to pay (14)', function () {
    $s = overdueCaseScenario('1000000');

    $promise = promiseFor($s, '200000', now()->addDays(3)->toDateString());

    expect($promise->status)->toBe(PromiseStatus::Open)
        ->and($promise->promised_amount)->toBe('200000.00')
        ->and($promise->outstanding_at_creation)->toBe('1000000.00')
        ->and($promise->booking_id)->toBe($s['booking']->id);
});

it('validates the promise amount against the outstanding (15)', function () {
    $s = overdueCaseScenario('1000000');

    expect(fn () => promiseFor($s, '0', now()->addDay()->toDateString()))->toThrow(DomainException::class);
    expect(fn () => promiseFor($s, '1500000', now()->addDay()->toDateString()))->toThrow(DomainException::class);

    // combined open promises may not exceed the outstanding
    promiseFor($s, '600000', now()->addDay()->toDateString());
    expect(fn () => promiseFor($s, '600000', now()->addDay()->toDateString()))->toThrow(DomainException::class);
});

it('moves the case to PROMISE_TO_PAY on a new promise (16)', function () {
    $s = overdueCaseScenario();
    promiseFor($s, '100000', now()->addDay()->toDateString());

    expect($s['case']->fresh()->status->value)->toBe('promise_to_pay');
});

it('marks a promise KEPT only through an actual M7 payment (17)', function () {
    $s = overdueCaseScenario('1000000');
    $promise = promiseFor($s, '200000', now()->addDays(5)->toDateString());

    // a button cannot mark it kept — only real money
    $p = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '200000',
    ], $s['actor']);
    app(VerifyPaymentAction::class)->handle($p, PaymentStatus::Success, $s['actor']);

    // observer runs; be explicit too
    app(EvaluatePaymentPromisesAction::class)->handle($s['booking']->fresh());

    expect($promise->fresh()->status)->toBe(PromiseStatus::Kept)
        ->and($promise->fresh()->fulfilled_by_payment_id)->toBe($p->id);
});

it('marks a promise BROKEN when its date passes unfulfilled (18)', function () {
    $s = overdueCaseScenario('1000000');
    $promise = PaymentPromise::factory()->create([
        'collection_case_id' => $s['case']->id,
        'booking_id' => $s['booking']->id,
        'promised_amount' => '200000',
        'outstanding_at_creation' => '1000000',
        'promise_date' => now()->subDay()->toDateString(),
        'status' => PromiseStatus::Open->value,
        'created_by' => $s['actor']->id,
    ]);

    app(EvaluatePaymentPromisesAction::class)->handle($s['booking']->fresh());

    expect($promise->fresh()->status)->toBe(PromiseStatus::Broken)
        ->and($promise->fresh()->broken_at)->not->toBeNull();
});

it('cancels an open promise (19)', function () {
    $s = overdueCaseScenario();
    $promise = promiseFor($s, '100000', now()->addDay()->toDateString());

    $cancelled = app(CancelPaymentPromiseAction::class)->handle($promise, $s['actor'], 'customer changed mind');

    expect($cancelled->status)->toBe(PromiseStatus::Cancelled);

    // a kept/broken promise cannot be cancelled
    $broken = PaymentPromise::factory()->broken()->create([
        'collection_case_id' => $s['case']->id, 'booking_id' => $s['booking']->id,
    ]);
    expect(fn () => app(CancelPaymentPromiseAction::class)->handle($broken, $s['actor']))->toThrow(DomainException::class);
});

it('a promise never changes the paid amount (20)', function () {
    $s = overdueCaseScenario('1000000');
    $ledger = app(PaymentLedger::class);
    $before = $ledger->bookingPaid($s['booking']->fresh())->store();

    promiseFor($s, '500000', now()->addDay()->toDateString());

    expect($ledger->bookingPaid($s['booking']->fresh())->store())->toBe($before)
        ->and($ledger->bookingOutstanding($s['booking']->fresh())->store())->toBe('1000000.00');
});
