<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Bookings\Concerns\PersistsBookingPricing;
use App\Actions\Bookings\Concerns\ValidatesBookingConsistency;
use App\Enums\BookingStatus;
use App\Enums\BuyerStatus;
use App\Enums\PlotStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Plot;
use App\Models\User;
use App\Support\Bookings\BookingBuyerValidator;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Confirms a PENDING booking — the point where money and inventory become
 * historical truth.
 *
 *   BEGIN
 *     lock the booking row            (serialises confirm/cancel/update on it)
 *     re-read status; idempotent if already CONFIRMED
 *     re-validate the buyer set
 *     lock the plot row               (serialises against every other booking)
 *     re-read plot status; must still be AVAILABLE / HOLD and active
 *     no other live booking for the plot
 *     recalculate the price from the persisted config
 *     freeze it into pricing_snapshot
 *     booking -> CONFIRMED (+ confirmed_at / confirmed_by)
 *     plot    -> BOOKED    (+ clear any M4 hold metadata)
 *   COMMIT   (anything throws -> full ROLLBACK: no snapshot, no plot change)
 *
 * A losing concurrent confirmation finds the plot already BOOKED and is
 * rejected with a DomainException — no UI guard involved.
 */
class ConfirmBookingAction
{
    use PersistsBookingPricing;
    use RunsInTransaction;
    use ValidatesBookingConsistency;

    public function __construct(private readonly BookingBuyerValidator $buyerValidator) {}

    public function handle(Booking $booking, User $actor): Booking
    {
        return $this->transaction(function () use ($booking, $actor): Booking {
            /** @var Booking $locked */
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
            $locked->load(['priceLines', 'bookingBuyers']);

            if ($locked->status === BookingStatus::Confirmed) {
                return $locked->load(['project', 'block', 'plot', 'bookingBuyers.buyer', 'priceLines']);
            }

            if ($locked->status !== BookingStatus::Pending) {
                throw new DomainException("Only a pending booking can be confirmed (this one is {$locked->status->label()}).");
            }

            // Guard against the buyer set drifting since PENDING.
            $this->buyerValidator->validate($locked->bookingBuyers->map(fn ($bb) => [
                'buyer_id' => $bb->buyer_id,
                'ownership_percentage' => (string) $bb->ownership_percentage,
                'is_primary' => (bool) $bb->is_primary,
            ])->all());

            $buyerIds = $locked->bookingBuyers->pluck('buyer_id')->all();
            $buyers = Buyer::query()->whereIn('id', $buyerIds)->get(['id', 'status']);

            if ($buyers->count() !== count(array_unique($buyerIds))) {
                throw new DomainException('A buyer on this booking no longer exists.');
            }

            if ($buyers->contains(fn (Buyer $b) => $b->status === BuyerStatus::Archived)) {
                throw new DomainException('A buyer on this booking has been archived — cannot confirm.');
            }

            /** @var Plot $plot */
            $plot = Plot::query()->whereKey($locked->plot_id)->lockForUpdate()->firstOrFail();

            $this->assertHierarchyConsistent($locked->project_id, $locked->block_id, $plot);
            $this->assertPlotBookable($plot);
            $this->assertNoLiveBooking($plot->id, $locked->id);

            // Recalculate from the stored inputs and freeze the result.
            $this->applyPricing(
                $locked,
                CalculateBookingPriceAction::configFromBooking($locked),
                freezeSnapshot: true,
            );

            $locked->forceFill([
                'status' => BookingStatus::Confirmed,
                'confirmed_at' => now(),
                'confirmed_by' => $actor->id,
            ])->save();

            $plot->forceFill(array_merge(
                ['status' => PlotStatus::Booked],
                Plot::clearedHoldAttributes(),
            ))->save();

            Log::info('booking.confirmed', [
                'booking_id' => $locked->id,
                'booking_number' => $locked->booking_number,
                'plot_id' => $plot->id,
                'final_amount' => $locked->final_amount,
                'by' => $actor->id,
            ]);

            return $locked->load(['project', 'block', 'plot', 'bookingBuyers.buyer', 'priceLines']);
        });
    }
}
