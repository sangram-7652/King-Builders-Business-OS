<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PromiseStatus;
use App\Models\Buyer;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Services\Payments\PaymentLedger;
use App\Support\Money;

/**
 * Aggregated collection summary for a buyer across all their confirmed bookings
 * (M8). Every money figure is derived from the M7 ledger.
 */
class BuyerCollectionProfile
{
    public function __construct(private readonly PaymentLedger $ledger) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Buyer $buyer): array
    {
        $bookings = $buyer->bookings()
            ->wherePivot('is_primary', true)
            ->where('bookings.status', BookingStatus::Confirmed->value)
            ->with('activePaymentPlan.installments')
            ->get();

        $value = Money::zero();
        $paid = Money::zero();
        $outstanding = Money::zero();
        $overdue = Money::zero();

        foreach ($bookings as $booking) {
            $value = $value->plus(Money::of($booking->final_amount));
            $paid = $paid->plus($this->ledger->bookingPaid($booking));
            $outstanding = $outstanding->plus($this->ledger->bookingOutstanding($booking)->clampToZero());
            $overdue = $overdue->plus($this->ledger->bookingOverdue($booking));
        }

        $bookingIds = $bookings->pluck('id');

        $lastPayment = Payment::query()
            ->whereIn('booking_id', $bookingIds)
            ->where('status', PaymentStatus::Success->value)
            ->latest('payment_date')
            ->first();

        return [
            'total_bookings' => $bookings->count(),
            'total_value' => $value->store(),
            'total_paid' => $paid->store(),
            'total_outstanding' => $outstanding->store(),
            'total_overdue' => $overdue->store(),
            'open_promises' => PaymentPromise::query()->whereIn('booking_id', $bookingIds)
                ->where('status', PromiseStatus::Open->value)->count(),
            'broken_promises' => PaymentPromise::query()->whereIn('booking_id', $bookingIds)
                ->where('status', PromiseStatus::Broken->value)->count(),
            'last_payment_date' => $lastPayment?->payment_date?->toDateString(),
            'last_payment_amount' => $lastPayment?->amount,
        ];
    }
}
