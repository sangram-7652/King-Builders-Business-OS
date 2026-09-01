<?php

declare(strict_types=1);

use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

it('renders the finance dashboard, payments list and booking payments page', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking'], $s['actor']);

    $this->actingAs(financeManager());

    $this->get(route('finance.dashboard'))->assertOk()->assertSee('Finance');
    $this->get(route('payments.index'))->assertOk()->assertSee('Payments');
    $this->get(route('payments.booking', $s['booking']))->assertOk()
        ->assertSee('Payment plan')
        ->assertSee('Outstanding');
});

it('renders payment + receipt detail screens', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking'], $s['actor']);
    $payment = app(VerifyPaymentAction::class)->handle(
        app(RecordPaymentAction::class)->handle(['booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => '250000'], $s['actor']),
        PaymentStatus::Success,
        $s['actor'],
    );

    $this->actingAs(financeManager());
    $this->get(route('payments.show', $payment))->assertOk()->assertSee($payment->payment_number);
    $this->get(route('receipts.show', $payment->receipt))->assertOk()->assertSee($payment->receipt->receipt_number);
});

it('shows a Financials card on a confirmed booking detail', function () {
    $s = confirmedBookingScenario('1000000');
    $viewer = makeUser(permissions: ['bookings.view', 'payment_plans.view', 'pricing.view']);

    $this->actingAs($viewer)->get(route('bookings.show', $s['booking']))
        ->assertOk()
        ->assertSee('Financials')
        ->assertSee('Outstanding');
});

it('shows Finance in the sidebar only for a permitted user', function () {
    $this->actingAs(cashier())->get(route('dashboard'))->assertOk()->assertSee('Finance');
    $this->actingAs(makeUser())->get(route('dashboard'))->assertOk()->assertDontSee('>Finance<', false);
});
