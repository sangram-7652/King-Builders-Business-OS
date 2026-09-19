<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Support\Money;
use App\Support\Payments\FinancialSummary;

/**
 * The single source of financial truth for M7. Every balance is DERIVED here
 * from `payments` — there is no trusted `paid_amount` column and no balance
 * maths in Blade / Livewire.
 *
 * Only `PaymentStatus::Success` payments count. A FAILED or REVERSED payment
 * never contributes to Paid.
 */
class PaymentLedger
{
    public function bookingPaid(Booking $booking): Money
    {
        $sum = Payment::query()
            ->where('booking_id', $booking->getKey())
            ->where('status', PaymentStatus::Success->value)
            ->sum('amount');

        return Money::of((string) $sum);
    }

    /** booking final amount − successful payments (may be negative = credit). */
    public function bookingOutstanding(Booking $booking): Money
    {
        return Money::of($booking->final_amount)->minus($this->bookingPaid($booking));
    }

    public function summary(Booking $booking): FinancialSummary
    {
        return new FinancialSummary(
            total: Money::of($booking->final_amount),
            paid: $this->bookingPaid($booking),
            outstanding: $this->bookingOutstanding($booking),
        );
    }
}
