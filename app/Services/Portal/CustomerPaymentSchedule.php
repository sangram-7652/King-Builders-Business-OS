<?php

declare(strict_types=1);

namespace App\Services\Portal;

use App\Models\Booking;
use App\Models\Installment;
use App\Services\Payments\PaymentLedger;

/**
 * Builds the customer-visible installment schedule for a booking (M15.2).
 * Every figure is derived from the M7 ledger — no balance is recomputed here
 * and there is no booking_value − paid arithmetic.
 */
class CustomerPaymentSchedule
{
    public function __construct(private readonly PaymentLedger $ledger) {}

    /**
     * @return array{
     *   plan_total: string,
     *   rows: list<array{number: int, name: string|null, due_date: string, amount: string, paid: string, outstanding: string, status: string}>
     * }|null
     */
    public function forBooking(Booking $booking): ?array
    {
        $plan = $booking->relationLoaded('activePaymentPlan')
            ? $booking->activePaymentPlan
            : $booking->activePaymentPlan()->with('installments')->first();

        if ($plan === null) {
            return null;
        }

        $plan->loadMissing('installments');

        $rows = $plan->installments
            ->sortBy('installment_number')
            ->map(function (Installment $installment) {
                $paid = $this->ledger->installmentPaid($installment);
                $outstanding = $this->ledger->installmentOutstanding($installment);

                return [
                    'number' => (int) $installment->installment_number,
                    'name' => $installment->name,
                    'due_date' => $installment->due_date->toDateString(),
                    'amount' => (string) $installment->amount,
                    'paid' => $paid->store(),
                    'outstanding' => $outstanding->store(),
                    'status' => $this->ledger->deriveInstallmentStatus($installment)->value,
                ];
            })
            ->values()
            ->all();

        return ['plan_total' => (string) $plan->total_amount, 'rows' => $rows];
    }
}
