<?php

declare(strict_types=1);

namespace App\Support\Payments;

use App\Models\Receipt;
use App\Services\Payments\PaymentLedger;
use App\Support\AmountInWords;

/**
 * The extra fields the receipt PDF needs beyond the `Receipt` record itself —
 * all read live from the booking's payment ledger + its related records. This
 * is presentation-only: it never writes anything and never touches the
 * ledger's calculation logic (see {@see PaymentLedger}), it only calls it.
 *
 * Shared by the staff receipt controller and the customer-portal one so the
 * two surfaces can never drift.
 */
final class ReceiptPdfData
{
    /**
     * @return array<string, mixed>
     */
    public static function build(Receipt $receipt): array
    {
        $receipt->loadMissing([
            'payment.paymentMode',
            'booking.project.city',
            'booking.block',
            'booking.plot.dimension',
            'buyer.city',
        ]);

        $booking = $receipt->booking;
        $plot = $booking?->plot;
        $buyer = $receipt->buyer;
        $ledger = app(PaymentLedger::class);

        return [
            'bookedBranch' => $booking?->project?->city?->name,
            'customerCode' => $buyer?->customer_code,
            'customerMobile' => $buyer?->phone,
            'customerCity' => $buyer?->city?->name ?: $buyer?->address,
            'plotArea' => $plot !== null ? $plot->area.' '.$plot->area_unit->abbreviation() : null,
            'plotDimension' => $plot?->dimension !== null
                ? self::trimDecimal($plot->dimension->width).' X '.self::trimDecimal($plot->dimension->length).' '.$plot->dimension->unit->abbreviation()
                : null,
            'plotFacing' => $plot?->facing?->label(),
            'phase' => $booking?->block?->name,
            'additionalCharges' => $booking?->charge_amount,
            'totalPaidAmount' => $booking !== null ? $ledger->bookingPaid($booking)->store() : null,
            'balanceAmount' => $booking !== null ? $ledger->bookingOutstanding($booking)->store() : null,
            'paidAmountInWords' => AmountInWords::rupees((string) $receipt->amount),
        ];
    }

    /** Drops a redundant ".00" / trailing zero from a decimal-cast value for display. */
    private static function trimDecimal(string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}
