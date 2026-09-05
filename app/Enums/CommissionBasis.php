<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Which existing financial truth a commission scheme is calculated against
 * (M14.3). Commission is NEVER computed from an invented value — it is always a
 * function of one of these M6 / M7 numbers.
 *
 *   BOOKING_VALUE    — the booking's frozen M6 `final_amount` snapshot
 *   COLLECTED_AMOUNT — the M7 successfully-collected amount for the booking
 *
 * Booking value, receivable, collected and commission stay separate concepts;
 * this only names the input.
 */
enum CommissionBasis: string
{
    use HasLabel;

    case BookingValue = 'booking_value';
    case CollectedAmount = 'collected_amount';

    public function label(): string
    {
        return match ($this) {
            self::BookingValue => 'Booking value (M6 final amount)',
            self::CollectedAmount => 'Collected amount (M7)',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::BookingValue => 'Booking value',
            self::CollectedAmount => 'Collected',
        };
    }
}
