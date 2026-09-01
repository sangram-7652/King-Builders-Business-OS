<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Bookings\Concerns\ValidatesBookingConsistency;
use App\Enums\BookingStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Plot;
use App\Models\User;
use App\Support\Bookings\BookingBuyerValidator;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Promotes a DRAFT booking to PENDING. This is the point the plot is claimed
 * (no other booking may be PENDING/CONFIRMED for it) even though Plot::status
 * does not change until confirmation.
 */
class SubmitBookingAction
{
    use RunsInTransaction;
    use ValidatesBookingConsistency;

    public function __construct(private readonly BookingBuyerValidator $buyerValidator) {}

    public function handle(Booking $booking, User $actor): Booking
    {
        return $this->transaction(function () use ($booking, $actor): Booking {
            /** @var Booking $locked */
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
            $locked->load('bookingBuyers');

            if ($locked->status === BookingStatus::Pending) {
                return $locked->load(['project', 'block', 'plot', 'bookingBuyers.buyer', 'priceLines']);
            }

            if ($locked->status !== BookingStatus::Draft) {
                throw new DomainException("Only a draft booking can be submitted (this one is {$locked->status->label()}).");
            }

            $this->buyerValidator->validate($locked->bookingBuyers->map(fn ($bb) => [
                'buyer_id' => $bb->buyer_id,
                'ownership_percentage' => (string) $bb->ownership_percentage,
                'is_primary' => (bool) $bb->is_primary,
            ])->all());

            /** @var Plot $plot */
            $plot = Plot::query()->whereKey($locked->plot_id)->lockForUpdate()->firstOrFail();

            $this->assertPlotBookable($plot);
            $this->assertNoLiveBooking($plot->id, $locked->id);

            $locked->forceFill(['status' => BookingStatus::Pending])->save();

            Log::info('booking.submitted', [
                'booking_id' => $locked->id,
                'booking_number' => $locked->booking_number,
                'plot_id' => $plot->id,
                'by' => $actor->id,
            ]);

            return $locked->load(['project', 'block', 'plot', 'bookingBuyers.buyer', 'priceLines']);
        });
    }
}
