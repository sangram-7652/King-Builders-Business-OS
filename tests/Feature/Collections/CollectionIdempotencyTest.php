<?php

declare(strict_types=1);

use App\Actions\Collections\ApproveBouncePenaltyAction;
use App\Actions\Collections\CompleteCollectionFollowUpAction;
use App\Actions\Collections\CreatePaymentPromiseAction;
use App\Actions\Collections\RecordChequeBounceAction;
use App\Actions\Collections\ScheduleCollectionFollowUpAction;
use App\Actions\Payments\RecordPaymentAction;
use App\Enums\CollectionFollowUpOutcome;
use App\Models\BouncePenalty;
use App\Models\ChequeBounce;
use App\Models\CollectionFollowUp;
use App\Models\PaymentPromise;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('a duplicate follow-up request with the same idempotency key creates one row', function () {
    $s = overdueCaseScenario();
    $data = ['follow_up_at' => now()->addDay()->toDateTimeString(), 'idempotency_key' => 'fu-1'];

    $a = app(ScheduleCollectionFollowUpAction::class)->handle($s['case'], $data, $s['actor']);
    $b = app(ScheduleCollectionFollowUpAction::class)->handle($s['case'], $data, $s['actor']);

    expect($b->id)->toBe($a->id)
        ->and(CollectionFollowUp::where('collection_case_id', $s['case']->id)->count())->toBe(1);
});

it('completing the same follow-up twice does not double-schedule or re-run', function () {
    $s = overdueCaseScenario();
    $fu = app(ScheduleCollectionFollowUpAction::class)->handle($s['case'], ['follow_up_at' => now()->toDateTimeString()], $s['actor']);

    $data = ['outcome' => CollectionFollowUpOutcome::CallBack->value, 'next_follow_up_at' => now()->addDays(2)->toDateTimeString()];
    $first = app(CompleteCollectionFollowUpAction::class)->handle($fu, $data, $s['actor']);
    $second = app(CompleteCollectionFollowUpAction::class)->handle($fu->fresh(), $data, $s['actor']);

    expect($second->id)->toBe($first->id)
        // 1 completed + exactly 1 follow-on scheduled (not 2)
        ->and(CollectionFollowUp::where('collection_case_id', $s['case']->id)->count())->toBe(2);
});

it('a duplicate promise request with the same idempotency key creates one promise', function () {
    $s = overdueCaseScenario('1000000');
    $data = ['booking_id' => $s['booking']->id, 'promised_amount' => '200000', 'promise_date' => now()->addDay()->toDateString(), 'idempotency_key' => 'pr-1'];

    $a = app(CreatePaymentPromiseAction::class)->handle($data, $s['actor']);
    $b = app(CreatePaymentPromiseAction::class)->handle($data, $s['actor']);

    expect($b->id)->toBe($a->id)
        ->and(PaymentPromise::where('booking_id', $s['booking']->id)->count())->toBe(1);
});

it('processing the same cheque bounce twice records one bounce and does not double-reverse', function () {
    $s = overdueCaseScenario('1000000');
    $payment = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => chequeMode()->id, 'amount' => '100000',
        'cheque_number' => 'C9', 'cheque_date' => now()->toDateString(),
    ], $s['actor']);

    $a = app(RecordChequeBounceAction::class)->handle($payment, ['bounce_date' => now()->toDateString(), 'bounce_reason' => 'NSF'], collectionManager());
    $b = app(RecordChequeBounceAction::class)->handle($payment->fresh(), ['bounce_date' => now()->toDateString(), 'bounce_reason' => 'NSF again'], collectionManager());

    expect($b->id)->toBe($a->id)
        ->and(ChequeBounce::where('payment_id', $payment->id)->count())->toBe(1);
});

it('approving the same penalty twice is a no-op', function () {
    $s = overdueCaseScenario();
    $bounce = ChequeBounce::factory()->create(['booking_id' => $s['booking']->id, 'collection_case_id' => $s['case']->id]);
    $penalty = BouncePenalty::factory()->create(['cheque_bounce_id' => $bounce->id, 'booking_id' => $s['booking']->id]);

    $first = app(ApproveBouncePenaltyAction::class)->handle($penalty, collectionManager());
    $second = app(ApproveBouncePenaltyAction::class)->handle($penalty->fresh(), collectionManager());

    expect($second->approved_at->eq($first->approved_at))->toBeTrue();
});
