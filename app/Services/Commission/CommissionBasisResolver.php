<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Enums\CommissionBasis;
use App\Models\Booking;
use App\Services\Payments\PaymentLedger;
use App\Support\Money;

/**
 * Produces the basis Money for a commission calculation (M14.4) — always an
 * existing M6 / M7 figure, never an invented value.
 *
 *   BOOKING_VALUE    → the booking's frozen M6 `final_amount` snapshot
 *   COLLECTED_AMOUNT → M7 successfully-collected money (PaymentLedger truth)
 */
class CommissionBasisResolver
{
    public function __construct(private readonly PaymentLedger $ledger) {}

    public function amountFor(Booking $booking, CommissionBasis $basis): Money
    {
        return match ($basis) {
            CommissionBasis::BookingValue => Money::of($booking->final_amount),
            CommissionBasis::CollectedAmount => $this->ledger->bookingPaid($booking),
        };
    }

    public function sourceLabel(CommissionBasis $basis): string
    {
        return match ($basis) {
            CommissionBasis::BookingValue => 'booking.final_amount (M6 frozen snapshot)',
            CommissionBasis::CollectedAmount => 'PaymentLedger::bookingPaid (M7 SUCCESS payments)',
        };
    }
}
