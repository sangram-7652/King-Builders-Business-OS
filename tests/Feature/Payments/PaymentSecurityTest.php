<?php

declare(strict_types=1);

use App\Actions\Payments\CreatePaymentPlanAction;
use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('rejects unauthorised payment creation (38)', function () {
    $s = confirmedBookingScenario();
    $viewer = makeUser(permissions: ['payments.view']);

    expect($viewer->can('create', Payment::class))->toBeFalse();

    $this->actingAs($viewer)->get(route('payments.index'))->assertOk();
    $this->actingAs(makeUser())->get(route('payments.index'))->assertForbidden();
});

it('rejects unauthorised verification (39)', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking'], $s['actor']);
    $payment = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '100000',
    ], $s['actor']);

    expect(cashier()->can('verify', $payment))->toBeFalse()
        ->and(financeManager()->can('verify', $payment))->toBeTrue();
});

it('rejects unauthorised allocation (40)', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking'], $s['actor']);
    $payment = app(VerifyPaymentAction::class)->handle(
        app(RecordPaymentAction::class)->handle(['booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '100000'], $s['actor']),
        PaymentStatus::Success,
        $s['actor'],
    );

    expect(cashier()->can('allocate', $payment))->toBeFalse()
        ->and(financeManager()->can('allocate', $payment))->toBeTrue();
});

it('rejects unauthorised reversal (41)', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking'], $s['actor']);
    $payment = app(VerifyPaymentAction::class)->handle(
        app(RecordPaymentAction::class)->handle(['booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '100000'], $s['actor']),
        PaymentStatus::Success,
        $s['actor'],
    );

    expect(cashier()->can('reverse', $payment))->toBeFalse();
    // payments are never deletable through the app
    expect(financeManager()->can('delete', $payment))->toBeFalse();
});

it('rejects unauthorised receipt access (42)', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking'], $s['actor']);
    $payment = app(VerifyPaymentAction::class)->handle(
        app(RecordPaymentAction::class)->handle(['booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '100000'], $s['actor']),
        PaymentStatus::Success,
        $s['actor'],
    );
    $receipt = $payment->receipt;

    $this->actingAs(makeUser())->get(route('receipts.pdf', $receipt))->assertForbidden();
    $this->actingAs(financeManager())->get(route('receipts.pdf', $receipt))->assertOk();
});

it('gates plan activation on payment_plans.activate', function () {
    $s = confirmedBookingScenario();
    $plan = app(CreatePaymentPlanAction::class)->handle($s['booking'], [
        'schedule' => [['type' => 'percentage', 'value' => '100', 'due_date' => now()->addMonth()->toDateString()]],
    ], $s['actor']);

    $creatorOnly = makeUser(permissions: ['payment_plans.view', 'payment_plans.create']);

    expect($creatorOnly->can('activate', $plan))->toBeFalse()
        ->and(financeManager()->can('activate', $plan))->toBeTrue();
});
