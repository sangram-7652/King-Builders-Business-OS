<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Enums\AgingBucket;
use App\Enums\BookingStatus;
use App\Enums\ChequeStatus;
use App\Enums\CollectionCaseStatus;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\PromiseStatus;
use App\Models\Booking;
use App\Models\BookingBuyer;
use App\Models\ChequeBounce;
use App\Models\CollectionCase;
use App\Models\CollectionFollowUp;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Services\Payments\PaymentLedger;
use App\Support\Collections\CollectionDashboardData;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Builds the collection dashboard (M8). All money comes from the M7
 * {@see PaymentLedger} / {@see AgingCalculator}; nothing is recomputed here.
 */
class CollectionDashboardService
{
    public function __construct(
        private readonly PaymentLedger $ledger,
        private readonly AgingCalculator $aging,
    ) {}

    public function build(?Carbon $asOf = null): CollectionDashboardData
    {
        $today = ($asOf ?? Carbon::today())->startOfDay();
        $weekEnd = $today->copy()->addDays((int) config('collections.due_soon_days'));

        $bookings = Booking::query()
            ->where('status', BookingStatus::Confirmed->value)
            ->with('activePaymentPlan.installments')
            ->get();

        $receivable = Money::zero();
        $collected = Money::zero();
        $outstanding = Money::zero();
        $overdue = Money::zero();
        $dueToday = Money::zero();
        $dueWeek = Money::zero();

        /** @var array<string, array{amount: Money, bookings: int, customers: int}> $aging */
        $aging = [];
        foreach (AgingBucket::cases() as $bucket) {
            $aging[$bucket->value] = ['amount' => Money::zero(), 'bookings' => 0, 'customers' => 0];
        }
        $agingCustomers = array_fill_keys(AgingBucket::values(), []);

        foreach ($bookings as $booking) {
            $receivable = $receivable->plus(Money::of($booking->final_amount));
            $collected = $collected->plus($this->ledger->bookingPaid($booking));
            $outstanding = $outstanding->plus($this->ledger->bookingOutstanding($booking)->clampToZero());
            $overdue = $overdue->plus($this->ledger->bookingOverdue($booking, $today));

            $plan = $booking->activePaymentPlan;
            if ($plan !== null) {
                foreach ($plan->installments as $installment) {
                    if ($installment->status === InstallmentStatus::Waived) {
                        continue;
                    }
                    $due = $installment->due_date->copy()->startOfDay();
                    $out = $this->ledger->installmentOutstanding($installment);

                    if ($due->equalTo($today)) {
                        $dueToday = $dueToday->plus($out);
                    }
                    if ($due->gte($today) && $due->lte($weekEnd)) {
                        $dueWeek = $dueWeek->plus($out);
                    }
                }
            }

            $bookingAging = $this->aging->agingForBooking($booking, $today);
            foreach ($bookingAging as $bucketValue => $amount) {
                if ($amount->isPositive()) {
                    $aging[$bucketValue]['amount'] = $aging[$bucketValue]['amount']->plus($amount);
                    $aging[$bucketValue]['bookings']++;
                    $agingCustomers[$bucketValue][$booking->id] = true;
                }
            }
        }

        foreach ($this->distinctCustomersByBucket($agingCustomers) as $bucketValue => $count) {
            $aging[$bucketValue]['customers'] = $count;
        }

        $collectedToday = Money::of((string) Payment::query()
            ->where('status', PaymentStatus::Success->value)
            ->whereDate('payment_date', $today)
            ->sum('amount'));

        $promisesDueAmount = Money::of((string) PaymentPromise::query()
            ->where('status', PromiseStatus::Open->value)
            ->whereDate('promise_date', $today)
            ->sum('promised_amount'));

        return new CollectionDashboardData(
            totalReceivable: $receivable,
            totalCollected: $collected,
            totalOutstanding: $outstanding,
            totalOverdue: $overdue,
            dueToday: $dueToday,
            dueThisWeek: $dueWeek,
            collectedToday: $collectedToday,
            expectedToday: $dueToday->plus($promisesDueAmount),
            aging: $aging,
            openFollowUps: CollectionFollowUp::query()->whereNull('completed_at')->count(),
            promisesDue: PaymentPromise::query()->where('status', PromiseStatus::Open->value)
                ->whereDate('promise_date', '<=', $today)->count(),
            brokenPromises: PaymentPromise::query()->where('status', PromiseStatus::Broken->value)->count(),
            pendingCheques: Payment::query()->where('status', PaymentStatus::Pending->value)
                ->where('cheque_status', ChequeStatus::Pending->value)->count(),
            bouncedCheques: ChequeBounce::query()->count(),
            openCases: CollectionCase::query()->where('status', '!=', CollectionCaseStatus::Resolved->value)->count(),
            unassignedCases: CollectionCase::query()
                ->where('status', '!=', CollectionCaseStatus::Resolved->value)
                ->whereNull('assigned_to')->count(),
        );
    }

    /**
     * @param  array<string, array<int, bool>>  $customersByBucket
     * @return array<string, int>
     */
    private function distinctCustomersByBucket(array $customersByBucket): array
    {
        $bookingIds = collect($customersByBucket)->flatMap(fn ($ids) => array_keys($ids))->unique()->all();

        if ($bookingIds === []) {
            return [];
        }

        // Map booking → primary buyer once.
        $primaryBuyer = BookingBuyer::query()
            ->whereIn('booking_id', $bookingIds)
            ->where('is_primary', true)
            ->pluck('buyer_id', 'booking_id');

        $out = [];
        foreach ($customersByBucket as $bucketValue => $ids) {
            $out[$bucketValue] = collect(array_keys($ids))
                ->map(fn ($bookingId) => $primaryBuyer[$bookingId] ?? "b{$bookingId}")
                ->unique()
                ->count();
        }

        return $out;
    }
}
