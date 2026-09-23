<?php

declare(strict_types=1);

namespace App\Support\Payments;

use App\Enums\PriceComponentType;
use App\Models\Booking;
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
            'booking.priceLines',
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
            // The booking's FROZEN pricing quantity (sq ft — the unit its rate
            // is in), not the plot's current area: a later plot edit or a
            // Plot Transfer must never make an old receipt's Area × Rate
            // disagree with its Total Plot Amount. Falls back to the plot's
            // own area only for a booking with no priced area.
            'plotArea' => $booking !== null && bccomp((string) $booking->base_area, '0', 4) > 0
                ? self::trimDecimalString((string) $booking->base_area).' sq ft'
                : ($plot !== null ? $plot->area.' '.$plot->area_unit->abbreviation() : null),
            // Full stored precision (up to 4 dp), trailing zeros trimmed.
            'rate' => $booking !== null ? self::trimDecimalString((string) $booking->base_rate) : null,
            'plotDimension' => $plot?->dimension !== null
                ? self::trimDecimal($plot->dimension->width).' X '.self::trimDecimal($plot->dimension->length).' '.$plot->dimension->unit->abbreviation()
                : null,
            'plotFacing' => $plot?->facing?->label(),
            'phase' => $booking?->block?->name,
            'additionalCharges' => $booking?->charge_amount,
            'totalPaidAmount' => $booking !== null ? $ledger->bookingPaid($booking)->store() : null,
            'balanceAmount' => $booking !== null ? $ledger->bookingOutstanding($booking)->store() : null,
            'paidAmountInWords' => AmountInWords::rupees((string) $receipt->amount),
            'discountRemark' => $booking !== null ? self::discountRemark($booking) : null,
        ];
    }

    /** Trailing-zero trim on a decimal string, without any float round-trip. */
    private static function trimDecimalString(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    /** Drops a redundant ".00" / trailing zero from a decimal-cast value for display. */
    private static function trimDecimal(string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    /**
     * The discount remark(s) entered on the booking's discount line(s), as
     * saved via the booking form — the receipt's "Remark" field prefers this
     * over the payment's own note when present (see resources/views/receipts/pdf.blade.php).
     */
    private static function discountRemark(Booking $booking): ?string
    {
        $remarks = $booking->priceLines
            ->filter(fn ($line) => $line->type === PriceComponentType::Discount)
            ->map(fn ($line) => trim((string) data_get($line->metadata, 'remark', '')))
            ->filter(fn (string $remark) => $remark !== '')
            ->unique()
            ->values();

        return $remarks->isEmpty() ? null : $remarks->implode('; ');
    }
}
