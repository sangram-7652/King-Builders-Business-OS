<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Enums\AgingBucket;
use App\Enums\InstallmentStatus;
use App\Models\Booking;
use App\Models\Installment;
use App\Services\Payments\PaymentLedger;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Aging of OVERDUE receivables (M8). Reads financial truth from the M7
 * {@see PaymentLedger} — it never recomputes a balance itself.
 *
 * Days overdue = today − installment due date. A paid / waived installment
 * never appears in aging; a partially-paid one contributes its remaining
 * outstanding.
 */
class AgingCalculator
{
    public function __construct(private readonly PaymentLedger $ledger) {}

    public function daysOverdue(Installment $installment, ?Carbon $asOf = null): int
    {
        $today = ($asOf ?? Carbon::today())->startOfDay();
        $due = $installment->due_date->copy()->startOfDay();

        return $due->lt($today) ? (int) $due->diffInDays($today) : 0;
    }

    public function bucketFor(Installment $installment, ?Carbon $asOf = null): ?AgingBucket
    {
        if ($installment->status === InstallmentStatus::Waived) {
            return null;
        }

        if (! $this->ledger->installmentOutstanding($installment)->isPositive()) {
            return null;
        }

        return AgingBucket::fromDaysOverdue($this->daysOverdue($installment, $asOf));
    }

    /**
     * Overdue outstanding for one booking, split by aging bucket.
     *
     * @return array<value-of<AgingBucket>, Money>
     */
    public function agingForBooking(Booking $booking, ?Carbon $asOf = null): array
    {
        $out = array_fill_keys(AgingBucket::values(), Money::zero());

        $plan = $booking->relationLoaded('activePaymentPlan')
            ? $booking->activePaymentPlan
            : $booking->activePaymentPlan()->with('installments')->first();

        if ($plan === null) {
            return $out;
        }

        foreach ($plan->installments as $installment) {
            $bucket = $this->bucketFor($installment, $asOf);

            if ($bucket === null) {
                continue;
            }

            $out[$bucket->value] = $out[$bucket->value]->plus($this->ledger->installmentOutstanding($installment));
        }

        return $out;
    }

    /**
     * Overdue installment lines for a booking with derived collection fields.
     *
     * @return Collection<int, array{installment: Installment, outstanding: Money, days_overdue: int, bucket: AgingBucket}>
     */
    public function overdueInstallments(Booking $booking, ?Carbon $asOf = null): Collection
    {
        $plan = $booking->relationLoaded('activePaymentPlan')
            ? $booking->activePaymentPlan
            : $booking->activePaymentPlan()->with('installments')->first();

        if ($plan === null) {
            return collect();
        }

        return $plan->installments
            ->map(function (Installment $installment) use ($asOf) {
                $bucket = $this->bucketFor($installment, $asOf);

                return $bucket === null ? null : [
                    'installment' => $installment,
                    'outstanding' => $this->ledger->installmentOutstanding($installment),
                    'days_overdue' => $this->daysOverdue($installment, $asOf),
                    'bucket' => $bucket,
                ];
            })
            ->filter()
            ->sortByDesc('days_overdue')
            ->values();
    }

    public function maxDaysOverdue(Booking $booking, ?Carbon $asOf = null): int
    {
        return (int) $this->overdueInstallments($booking, $asOf)->max('days_overdue') ?: 0;
    }
}
