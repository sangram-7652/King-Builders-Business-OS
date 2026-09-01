<?php

declare(strict_types=1);

namespace App\Support\Bookings;

use App\Exceptions\DomainException;
use App\Support\Money;

/**
 * Enforces the co-ownership rules for a booking's buyers (M6):
 *
 *  - at least one buyer
 *  - no buyer listed twice
 *  - every ownership share is > 0 and ≤ 100
 *  - exactly one buyer flagged primary
 *  - the shares total EXACTLY 100.00
 */
final class BookingBuyerValidator
{
    /**
     * @param  array<int, array{buyer_id: int|string, ownership_percentage: mixed, is_primary: mixed}>  $rows
     * @return list<array{buyer_id: int, ownership_percentage: string, is_primary: bool}>
     */
    public function validate(array $rows): array
    {
        if ($rows === []) {
            throw new DomainException('A booking needs at least one buyer.');
        }

        $normalised = [];
        $seen = [];
        $primaryCount = 0;
        $total = Money::zero();

        foreach ($rows as $row) {
            $buyerId = (int) $row['buyer_id'];

            if ($buyerId <= 0) {
                throw new DomainException('Every booking buyer must be a real buyer.');
            }

            if (isset($seen[$buyerId])) {
                throw new DomainException('The same buyer cannot be added to a booking twice.');
            }
            $seen[$buyerId] = true;

            $share = Money::of((string) $row['ownership_percentage']);

            if (! $share->isPositive()) {
                throw new DomainException('Each ownership share must be greater than 0%.');
            }

            if ($share->greaterThan(Money::of('100'))) {
                throw new DomainException('An ownership share cannot exceed 100%.');
            }

            $isPrimary = filter_var($row['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $primaryCount += $isPrimary ? 1 : 0;
            $total = $total->plus($share);

            $normalised[] = [
                'buyer_id' => $buyerId,
                'ownership_percentage' => $share->store(),
                'is_primary' => $isPrimary,
            ];
        }

        if ($primaryCount !== 1) {
            throw new DomainException('Exactly one buyer must be marked as primary.');
        }

        if (! $total->equals(Money::of('100'))) {
            throw new DomainException("Ownership shares must total exactly 100% (currently {$total->store()}%).");
        }

        return $normalised;
    }
}
