<?php

declare(strict_types=1);

namespace App\Services\Portal;

use App\Enums\BookingStatus;
use App\Enums\DocumentStatus;
use App\Enums\InstallmentStatus;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Document;
use App\Models\Installment;
use App\Services\Collections\BuyerCollectionProfile;
use App\Services\Payments\PaymentLedger;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Efficient read-only aggregate for the customer portal dashboard (M15).
 *
 * Every money figure comes from the existing M7 ledger / M8 buyer profile —
 * this service NEVER recomputes a balance and NEVER does booking_value − paid.
 */
class CustomerPortfolioService
{
    public function __construct(
        private readonly BuyerCollectionProfile $collectionProfile,
        private readonly PaymentLedger $ledger,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(Buyer $customer): array
    {
        $bookingIds = $this->bookingIds($customer);

        $bookings = Booking::query()
            ->whereKey($bookingIds)
            ->with('activePaymentPlan.installments')
            ->get(['id', 'status', 'final_amount', 'plot_id']);

        $confirmed = $bookings->where('status', BookingStatus::Confirmed);

        // M8 buyer collection profile is the single source of truth for money.
        $money = $this->collectionProfile->for($customer);

        $nextDue = $this->nextDue($confirmed->pluck('id'));

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
            'total_value' => $money['total_value'],
            'paid' => $money['total_paid'],
            'outstanding' => $money['total_outstanding'],
            'overdue' => $money['total_overdue'],
            'next_due' => $nextDue,
            'documents_pending' => $pendingDocs,
            'last_payment_date' => $money['last_payment_date'],
            'last_payment_amount' => $money['last_payment_amount'],
        ];
    }

    /** @return list<int> */
    public function bookingIds(Buyer $customer): array
    {
        return $customer->bookingBuyers()->pluck('booking_id')->all();
    }

    /**
     * @param  Collection<int, int>  $confirmedBookingIds
     * @return array{amount: string, due_date: string|null}|null
     */
    private function nextDue($confirmedBookingIds): ?array
    {
        if ($confirmedBookingIds->isEmpty()) {
            return null;
        }

        /** @var Installment|null $installment */
        $installment = Installment::query()
            ->whereHas('paymentPlan', fn ($q) => $q->whereIn('booking_id', $confirmedBookingIds)
                ->whereIn('status', ['draft', 'active']))
            ->whereNotIn('status', [InstallmentStatus::Paid->value, InstallmentStatus::Waived->value])
            ->orderBy('due_date')
            ->first();

        if ($installment === null) {
            return null;
        }

        $outstanding = $this->ledger->installmentOutstanding($installment);

        return [
            'amount' => $outstanding->isPositive() ? $outstanding->store() : Money::of($installment->amount)->store(),
            'due_date' => $installment->due_date->toDateString(),
        ];
    }
}
