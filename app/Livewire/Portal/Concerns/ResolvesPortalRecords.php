<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Concerns;

use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Receipt;

/**
 * Every portal query is scoped to the authenticated customer's own records
 * (M15). A URL id is NEVER trusted — it is always filtered through the
 * customer's booking set, and a miss is a 404 (indistinguishable from "does
 * not exist"). Customers only ever see SUCCESS payments and non-void receipts.
 */
trait ResolvesPortalRecords
{
    use InteractsWithCustomer;

    /** @var list<int>|null */
    private ?array $cachedBookingIds = null;

    /** @return list<int> */
    protected function customerBookingIds(): array
    {
        return $this->cachedBookingIds ??= $this->customer()
            ->bookingBuyers()->pluck('booking_id')->map(fn ($id) => (int) $id)->all();
    }

    protected function ownsBooking(int $bookingId): bool
    {
        return in_array($bookingId, $this->customerBookingIds(), true);
    }

    protected function bookingOr404(int $bookingId): Booking
    {
        abort_unless($this->ownsBooking($bookingId), 404);

        return Booking::query()
            ->whereKey($bookingId)
            ->with(['project:id,name', 'block:id,name', 'plot:id,plot_number'])
            ->firstOrFail();
    }

    protected function paymentOr404(int $paymentId): Payment
    {
        return Payment::query()
            ->whereKey($paymentId)
            ->whereIn('booking_id', $this->customerBookingIds() ?: [0])
            ->where('status', PaymentStatus::Success->value)
            ->with(['paymentMode:id,name', 'booking:id,booking_number', 'receipt'])
            ->firstOr(fn () => abort(404));
    }

    protected function receiptOr404(int $receiptId): Receipt
    {
        return Receipt::query()
            ->whereKey($receiptId)
            ->where(function ($q): void {
                $q->where('buyer_id', $this->customer()->getKey())
                    ->orWhereIn('booking_id', $this->customerBookingIds() ?: [0]);
            })
            ->whereNull('voided_at')
            ->firstOr(fn () => abort(404));
    }
}
