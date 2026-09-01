<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Enums\AgingBucket;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Installment;
use App\Models\Payment;
use App\Services\Payments\PaymentLedger;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only report builders for M8. Every figure is derived from the M7 ledger
 * / {@see AgingCalculator} — no second balance engine.
 */
class CollectionReportService
{
    public function __construct(
        private readonly PaymentLedger $ledger,
        private readonly AgingCalculator $aging,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function confirmedBookings(): Collection
    {
        return Booking::query()
            ->where('status', BookingStatus::Confirmed->value)
            ->with(['project:id,name', 'plot:id,plot_number', 'primaryBookingBuyer.buyer', 'activePaymentPlan.installments'])
            ->get();
    }

    /**
     * Outstanding report — one row per confirmed booking with a balance.
     *
     * @return list<array<string, mixed>>
     */
    public function outstanding(): array
    {
        return $this->confirmedBookings()
            ->map(function (Booking $booking) {
                $outstanding = $this->ledger->bookingOutstanding($booking);

                return $outstanding->isPositive() ? [
                    'customer' => $booking->primaryBookingBuyer?->buyer?->fullName() ?? '—',
                    'booking' => $booking->booking_number,
                    'plot' => $booking->plot?->plot_number ?? '—',
                    'project' => $booking->project?->name ?? '—',
                    'total' => Money::of($booking->final_amount)->store(),
                    'paid' => $this->ledger->bookingPaid($booking)->store(),
                    'outstanding' => $outstanding->store(),
                ] : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Overdue report — one row per overdue installment.
     *
     * @return list<array<string, mixed>>
     */
    public function overdue(?Carbon $asOf = null): array
    {
        $rows = [];

        foreach ($this->confirmedBookings() as $booking) {
            foreach ($this->aging->overdueInstallments($booking, $asOf) as $line) {
                /** @var Installment $installment */
                $installment = $line['installment'];

                $rows[] = [
                    'customer' => $booking->primaryBookingBuyer?->buyer?->fullName() ?? '—',
                    'booking' => $booking->booking_number,
                    'plot' => $booking->plot?->plot_number ?? '—',
                    'installment' => $installment->label(),
                    'due_date' => $installment->due_date->toDateString(),
                    'amount' => Money::of($installment->amount)->store(),
                    'paid' => $this->ledger->installmentPaid($installment)->store(),
                    'outstanding' => $line['outstanding']->store(),
                    'days_overdue' => $line['days_overdue'],
                    'bucket' => $line['bucket']->value,
                ];
            }
        }

        usort($rows, fn ($a, $b) => $b['days_overdue'] <=> $a['days_overdue']);

        return $rows;
    }

    /**
     * Aging report — one row per bucket.
     *
     * @return list<array<string, mixed>>
     */
    public function aging(?Carbon $asOf = null): array
    {
        $totals = array_fill_keys(AgingBucket::values(), ['amount' => Money::zero(), 'bookings' => 0, 'customers' => []]);

        foreach ($this->confirmedBookings() as $booking) {
            $buyerKey = $booking->primaryBookingBuyer?->buyer_id ?? "b{$booking->id}";
            foreach ($this->aging->agingForBooking($booking, $asOf) as $bucketValue => $amount) {
                if ($amount->isPositive()) {
                    $totals[$bucketValue]['amount'] = $totals[$bucketValue]['amount']->plus($amount);
                    $totals[$bucketValue]['bookings']++;
                    $totals[$bucketValue]['customers'][$buyerKey] = true;
                }
            }
        }

        return collect(AgingBucket::cases())->map(fn (AgingBucket $bucket) => [
            'bucket' => $bucket->value,
            'label' => $bucket->label(),
            'amount' => $totals[$bucket->value]['amount']->store(),
            'booking_count' => $totals[$bucket->value]['bookings'],
            'customer_count' => count($totals[$bucket->value]['customers']),
        ])->all();
    }

    /**
     * Collection performance — successful collections grouped by date + collector.
     *
     * @return list<array<string, mixed>>
     */
    public function performance(Carbon $from, Carbon $to): array
    {
        $rows = Payment::query()
            ->where('status', PaymentStatus::Success->value)
            ->whereDate('payment_date', '>=', $from->toDateString())
            ->whereDate('payment_date', '<=', $to->toDateString())
            ->with(['verifiedBy:id,name', 'receivedBy:id,name'])
            ->get()
            ->groupBy(fn (Payment $p) => $p->payment_date->toDateString().'|'.($p->verified_by ?? $p->received_by ?? 0));

        return $rows->map(function (Collection $group) {
            /** @var Payment $first */
            $first = $group->first();

            return [
                'date' => $first->payment_date->toDateString(),
                'collector' => $first->verifiedBy?->name ?? $first->receivedBy?->name ?? 'System',
                'amount' => $group->reduce(fn (Money $c, Payment $p) => $c->plus(Money::of($p->amount)), Money::zero())->store(),
                'booking_count' => $group->pluck('booking_id')->unique()->count(),
            ];
        })->sortByDesc('date')->values()->all();
    }
}
