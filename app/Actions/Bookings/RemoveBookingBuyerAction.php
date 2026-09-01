<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Exceptions\DomainException;
use App\Models\Booking;

/**
 * Removes one buyer from an editable booking. Callers supply the rebalanced
 * shares (and, if the primary is being removed, the new primary) so the
 * remaining set still totals 100% with exactly one primary.
 */
class RemoveBookingBuyerAction
{
    public function __construct(private readonly SyncBookingBuyersAction $sync) {}

    /**
     * @param  array<int, array{buyer_id: int, ownership_percentage: mixed, is_primary?: bool}>  $rebalanced
     */
    public function handle(Booking $booking, int $buyerId, array $rebalanced = []): Booking
    {
        if (! $booking->isEditable()) {
            throw new DomainException('Buyers can only be changed while a booking is a draft or pending.');
        }

        $rows = $booking->bookingBuyers
            ->reject(fn ($bb) => $bb->buyer_id === $buyerId)
            ->map(function ($bb) use ($rebalanced) {
                $override = collect($rebalanced)->firstWhere('buyer_id', $bb->buyer_id);

                return [
                    'buyer_id' => $bb->buyer_id,
                    'ownership_percentage' => $override['ownership_percentage'] ?? $bb->ownership_percentage,
                    'is_primary' => $override['is_primary'] ?? $bb->is_primary,
                ];
            })
            ->values()
            ->all();

        if ($rows === []) {
            throw new DomainException('A booking must keep at least one buyer.');
        }

        return $this->sync->handle($booking, $rows);
    }
}
