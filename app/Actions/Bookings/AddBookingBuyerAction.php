<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Exceptions\DomainException;
use App\Models\Booking;

/**
 * Adds one buyer to an editable booking. The resulting set must still satisfy
 * the co-ownership rules — callers pass the rebalanced shares for the buyers
 * already on the booking.
 */
class AddBookingBuyerAction
{
    public function __construct(private readonly SyncBookingBuyersAction $sync) {}

    /**
     * @param  array<int, array{buyer_id: int, ownership_percentage: mixed, is_primary?: bool}>  $rebalanced
     *                                                                                                        New shares for the buyers already on the booking (keyed anyhow).
     */
    public function handle(Booking $booking, int $buyerId, string $ownershipPercentage, bool $isPrimary, array $rebalanced = []): Booking
    {
        if (! $booking->isEditable()) {
            throw new DomainException('Buyers can only be changed while a booking is a draft or pending.');
        }

        $rows = [];

        foreach ($booking->bookingBuyers as $existing) {
            $override = collect($rebalanced)->firstWhere('buyer_id', $existing->buyer_id);

            $rows[] = [
                'buyer_id' => $existing->buyer_id,
                'ownership_percentage' => $override['ownership_percentage'] ?? $existing->ownership_percentage,
                'is_primary' => $isPrimary ? false : ($override['is_primary'] ?? $existing->is_primary),
            ];
        }

        $rows[] = [
            'buyer_id' => $buyerId,
            'ownership_percentage' => $ownershipPercentage,
            'is_primary' => $isPrimary,
        ];

        return $this->sync->handle($booking, $rows);
    }
}
