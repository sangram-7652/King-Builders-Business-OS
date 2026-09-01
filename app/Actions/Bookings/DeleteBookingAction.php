<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Soft-deletes a booking. Only a DRAFT or a CANCELLED booking can be removed —
 * a PENDING or CONFIRMED booking must be cancelled first so the plot is
 * released and the financial record is closed rather than silently vanishing.
 */
class DeleteBookingAction
{
    use RunsInTransaction;

    public function handle(Booking $booking, User $actor): void
    {
        if (! $booking->isDraft() && ! $booking->isCancelled()) {
            throw new DomainException('Only a draft or cancelled booking can be deleted. Cancel it first.');
        }

        $this->transaction(function () use ($booking, $actor): void {
            $booking->bookingBuyers()->delete();
            $booking->priceLines()->delete();
            $booking->delete();

            Log::info('booking.deleted', [
                'booking_id' => $booking->id,
                'booking_number' => $booking->booking_number,
                'by' => $actor->id,
            ]);
        });
    }
}
