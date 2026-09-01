<?php

declare(strict_types=1);

use App\Actions\Payments\GenerateReceiptAction;
use App\Actions\Payments\RecordPaymentAction;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Receipt;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function verifiedPayment(array $s, string $amount = '250000'): Payment
{
    $p = app(RecordPaymentAction::class)->handle([
        'booking_id' => $s['booking']->id, 'payment_mode_id' => cashMode()->id, 'amount' => $amount, 'reference_number' => 'REF-9',
    ], $s['actor']);

    return app(VerifyPaymentAction::class)->handle($p, PaymentStatus::Success, $s['actor']);
}

it('issues a receipt automatically on successful verification (29)', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking'], $s['actor']);

    $payment = verifiedPayment($s);
    $receipt = $payment->receipt;

    expect($receipt)->not->toBeNull()
        ->and($receipt->receipt_number)->toBe('RCPT-000001')
        ->and($receipt->buyer_id)->toBe($s['buyer']->id)
        ->and($receipt->buyer_name_snapshot)->toBe($s['buyer']->fullName())
        ->and($receipt->payment_mode_label)->toBe('Cash')
        ->and($receipt->reference_number)->toBe('REF-9')
        ->and($receipt->issued_by)->toBe($s['actor']->id);
});

it('generates unique receipt numbers and one receipt per payment (30)', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking'], $s['actor']);

    $a = verifiedPayment($s, '100000');
    $b = verifiedPayment($s, '100000');

    expect($a->receipt->receipt_number)->toBe('RCPT-000001')
        ->and($b->receipt->receipt_number)->toBe('RCPT-000002');

    // calling generate again returns the same receipt (idempotent)
    $again = app(GenerateReceiptAction::class)->handle($a->fresh(), $s['actor']);
    expect($again->id)->toBe($a->receipt->id);

    expect(fn () => Receipt::factory()->create(['receipt_number' => 'RCPT-000001']))
        ->toThrow(QueryException::class);
});

it('records the correct amount and payment reference on the receipt (31)', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking'], $s['actor']);

    $payment = verifiedPayment($s, '333333.33');

    expect($payment->receipt->amount)->toBe('333333.33')
        ->and($payment->receipt->payment_date->toDateString())->toBe($payment->payment_date->toDateString());
});

it('renders the receipt as a tenant-branded PDF (32)', function () {
    $s = confirmedBookingScenario('1000000');
    activePlanFor($s['booking'], $s['actor']);
    $payment = verifiedPayment($s);

    $receipt = $payment->receipt->load(['payment.paymentMode', 'booking.project', 'issuedBy']);

    $bytes = Pdf::loadView('receipts.pdf', [
        'receipt' => $receipt,
        'brand' => ['name' => 'King Builders', 'primary' => '#2563eb'],
    ])->output();

    expect(str_starts_with($bytes, '%PDF'))->toBeTrue()
        ->and(strlen($bytes))->toBeGreaterThan(1000);

    // the HTTP route also works for an authorised user
    seedRbac();
    $this->actingAs(financeManager())
        ->get(route('receipts.pdf', $receipt))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});
