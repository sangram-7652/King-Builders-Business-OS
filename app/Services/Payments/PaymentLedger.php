<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\InstallmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentPlan;
use App\Support\Money;
use App\Support\Payments\FinancialSummary;
use Illuminate\Support\Carbon;

/**
 * The single source of financial truth for M7. Every balance is DERIVED here
 * from Payment → PaymentAllocation → Installment plus payment status — there is
 * no trusted `paid_amount` column and no balance maths in Blade / Livewire.
 *
 * Only `PaymentStatus::Success` payments and their allocations count.
 */
class PaymentLedger
{
    // --- Installment level ---------------------------------------------

    public function installmentPaid(Installment $installment): Money
    {
        $sum = PaymentAllocation::query()
            ->where('installment_id', $installment->getKey())
            ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::Success->value))
            ->sum('amount');

        return Money::of((string) $sum);
    }

    public function installmentOutstanding(Installment $installment): Money
    {
        if ($installment->status === InstallmentStatus::Waived) {
            return Money::zero();
        }

        return Money::of($installment->amount)->minus($this->installmentPaid($installment))->clampToZero();
    }

    /**
     * Recompute an installment's status from the ledger + its due date.
     * WAIVED is sticky — only an explicit un-waive changes it.
     */
    public function deriveInstallmentStatus(Installment $installment, ?Carbon $asOf = null): InstallmentStatus
    {
        if ($installment->status === InstallmentStatus::Waived) {
            return InstallmentStatus::Waived;
        }

        $today = ($asOf ?? Carbon::today())->startOfDay();
        $amount = Money::of($installment->amount);
        $paid = $this->installmentPaid($installment);
        $pastDue = $installment->due_date->startOfDay()->lt($today);

        if (! $paid->lessThan($amount)) {
            return InstallmentStatus::Paid;
        }

        if ($paid->isPositive()) {
            return $pastDue ? InstallmentStatus::Overdue : InstallmentStatus::PartiallyPaid;
        }

        if ($pastDue) {
            return InstallmentStatus::Overdue;
        }

        return $installment->due_date->startOfDay()->equalTo($today)
            ? InstallmentStatus::Due
            : InstallmentStatus::Upcoming;
    }

    // --- Payment level ------------------------------------------------

    public function paymentAllocated(Payment $payment): Money
    {
        $sum = PaymentAllocation::query()->where('payment_id', $payment->getKey())->sum('amount');

        return Money::of((string) $sum);
    }

    /** The part of a SUCCESS payment not yet applied to any installment. */
    public function paymentUnallocated(Payment $payment): Money
    {
        if ($payment->status !== PaymentStatus::Success) {
            return Money::zero();
        }

        return Money::of($payment->amount)->minus($this->paymentAllocated($payment))->clampToZero();
    }

    // --- Booking level ----------------------------------------------

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

    public function bookingOverdue(Booking $booking, ?Carbon $asOf = null): Money
    {
        $plan = $this->livePlan($booking);

        if ($plan === null) {
            return Money::zero();
        }

        $today = ($asOf ?? Carbon::today())->startOfDay();
        $overdue = Money::zero();

        foreach ($plan->installments as $installment) {
            if ($installment->status === InstallmentStatus::Waived) {
                continue;
            }

            if ($installment->due_date->startOfDay()->lt($today)) {
                $overdue = $overdue->plus($this->installmentOutstanding($installment));
            }
        }

        return $overdue;
    }

    public function bookingUnallocated(Booking $booking): Money
    {
        $total = Money::zero();

        $payments = Payment::query()
            ->where('booking_id', $booking->getKey())
            ->where('status', PaymentStatus::Success->value)
            ->get();

        foreach ($payments as $payment) {
            $total = $total->plus($this->paymentUnallocated($payment));
        }

        return $total;
    }

    public function summary(Booking $booking, ?Carbon $asOf = null): FinancialSummary
    {
        $plan = $this->livePlan($booking);

        $installmentCount = $plan?->installments->count() ?? 0;
        $settled = $plan
            ? $plan->installments->filter(fn (Installment $i) => $i->status->isSettled())->count()
            : 0;

        return new FinancialSummary(
            total: Money::of($booking->final_amount),
            planned: Money::of($plan?->total_amount ?? '0'),
            paid: $this->bookingPaid($booking),
            outstanding: $this->bookingOutstanding($booking),
            overdue: $this->bookingOverdue($booking, $asOf),
            unallocated: $this->bookingUnallocated($booking),
            installmentCount: $installmentCount,
            settledInstallmentCount: $settled,
        );
    }

    private function livePlan(Booking $booking): ?PaymentPlan
    {
        return $booking->relationLoaded('activePaymentPlan')
            ? $booking->activePaymentPlan?->loadMissing('installments')
            : $booking->activePaymentPlan()->with('installments')->first();
    }
}
