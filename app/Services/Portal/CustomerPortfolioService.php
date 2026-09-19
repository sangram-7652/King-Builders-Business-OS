<?php

declare(strict_types=1);

namespace App\Services\Portal;

use App\Enums\BookingStatus;
use App\Enums\DocumentStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Document;
use App\Models\Payment;
use App\Services\Payments\PaymentLedger;
use App\Support\Money;

/**
 * Efficient read-only aggregate for the customer portal dashboard (M15).
 *
 * Every money figure comes from the existing M7 ledger — this service NEVER
 * recomputes a balance and NEVER does booking_value − paid.
 */
class CustomerPortfolioService
{
    public function __construct(private readonly PaymentLedger $ledger) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(Buyer $customer): array
    {
        $bookingIds = $this->bookingIds($customer);

        $bookings = Booking::query()
            ->whereKey($bookingIds)
            ->get(['id', 'status', 'final_amount', 'plot_id']);

        $confirmed = $bookings->where('status', BookingStatus::Confirmed);

        $totalValue = Money::zero();
        $totalPaid = Money::zero();
        $totalOutstanding = Money::zero();

        foreach ($confirmed as $booking) {
            $totalValue = $totalValue->plus(Money::of($booking->final_amount));
            $totalPaid = $totalPaid->plus($this->ledger->bookingPaid($booking));
            $totalOutstanding = $totalOutstanding->plus($this->ledger->bookingOutstanding($booking)->clampToZero());
        }

        $lastPayment = Payment::query()
            ->whereIn('booking_id', $confirmed->pluck('id'))
            ->where('status', PaymentStatus::Success->value)
            ->orderByDesc('verified_at')
            ->first(['amount', 'verified_at']);

        $pendingDocs = Document::query()
            ->where(function ($q) use ($customer, $bookingIds): void {
                $q->where(fn ($w) => $w->where('documentable_type', $customer->getMorphClass())->where('documentable_id', $customer->id))
                    ->orWhere(fn ($w) => $w->where('documentable_type', (new Booking)->getMorphClass())->whereIn('documentable_id', $bookingIds));
            })
            ->where('status', '!=', DocumentStatus::Verified->value)
            ->count();

        return [
            'bookings' => $bookings->count(),
            'confirmed_bookings' => $confirmed->count(),
            'plots' => $bookings->pluck('plot_id')->filter()->unique()->count(),
            'total_value' => $totalValue->store(),
            'paid' => $totalPaid->store(),
            'outstanding' => $totalOutstanding->store(),
            'documents_pending' => $pendingDocs,
            'last_payment_date' => $lastPayment?->verified_at?->toDateString(),
            'last_payment_amount' => $lastPayment !== null ? Money::of($lastPayment->amount)->store() : null,
        ];
    }

    /** @return list<int> */
    public function bookingIds(Buyer $customer): array
    {
        return $customer->bookingBuyers()->pluck('booking_id')->all();
    }
}
