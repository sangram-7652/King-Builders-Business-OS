<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\BuyerStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Buyer;
use App\Support\Bookings\BookingBuyerValidator;
use App\Support\Concerns\RunsInTransaction;

/**
 * Replaces a booking's entire buyer set in one transaction. The co-ownership
 * invariants (≥1 buyer, one primary, shares total 100%) are checked BEFORE
 * anything is written — never a partial save.
 */
class SyncBookingBuyersAction
{
    use RunsInTransaction;

    public function __construct(private readonly BookingBuyerValidator $validator) {}

    /**
     * @param  array<int, array{buyer_id: int|string, ownership_percentage: mixed, is_primary: mixed}>  $rows
     */
    public function handle(Booking $booking, array $rows): Booking
    {
        $normalised = $this->validator->validate($rows);

        $ids = array_column($normalised, 'buyer_id');
        $found = Buyer::query()->whereIn('id', $ids)->get(['id', 'status']);

        if ($found->count() !== count($ids)) {
            throw new DomainException('One or more selected buyers no longer exist.');
        }

        if ($found->contains(fn (Buyer $b) => $b->status === BuyerStatus::Archived)) {
            throw new DomainException('An archived buyer cannot be added to a booking.');
        }

        return $this->transaction(function () use ($booking, $normalised): Booking {
            $booking->bookingBuyers()->delete();

            foreach ($normalised as $row) {
                $booking->bookingBuyers()->create($row);
            }

            return $booking->load(['bookingBuyers.buyer', 'buyers']);
        });
    }
}
