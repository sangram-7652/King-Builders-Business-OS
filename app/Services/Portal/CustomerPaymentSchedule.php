<?php

declare(strict_types=1);

namespace App\Services\Portal;

use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\Payments\PaymentLedger;

/**
 * Builds the customer-visible payment history for a booking (M15.2). Every
 * figure is derived from the M7 ledger — no balance is recomputed here and
 * there is no booking_value − paid arithmetic. There is no installment
 * schedule to show: a booking is paid directly, not against a plan.
 */
class CustomerPaymentSchedule
{
    public function __construct(private readonly PaymentLedger $ledger) {}

    /**
     * @return array{
     *   total: string, paid: string, outstanding: string,
     *   rows: list<array{payment_number: string, date: string, amount: string, mode: string|null, receipt_number: string|null}>
     * }
     */
    public function forBooking(Booking $booking): array
    {
        $payments = Payment::query()
            ->where('booking_id', $booking->getKey())
            ->where('status', PaymentStatus::Success->value)
            ->with(['paymentMode', 'receipt'])
            ->orderByDesc('payment_date')
            ->get();

        $rows = $payments->map(fn (Payment $payment) => [
            'payment_number' => $payment->payment_number,
            'date' => $payment->payment_date->toDateString(),
            'amount' => (string) $payment->amount,
            'mode' => $payment->paymentMode?->name,
            'receipt_number' => $payment->receipt?->receipt_number,
        ])->values()->all();

        return [
            'total' => (string) $booking->final_amount,
            'paid' => $this->ledger->bookingPaid($booking)->store(),
            'outstanding' => $this->ledger->bookingOutstanding($booking)->store(),
            'rows' => $rows,
        ];
    }
}
