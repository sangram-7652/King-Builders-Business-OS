<?php

declare(strict_types=1);

namespace App\Support\Pricing;

use App\Support\Money;

/**
 * The authoritative result of a booking price calculation. Everything is a
 * {@see Money} internally; `->toBookingAttributes()` / `->toSnapshot()` render
 * the 2-dp strings that get persisted.
 */
final class PriceBreakdown
{
    /**
     * @param  list<ResolvedPriceLine>  $lines
     */
    public function __construct(
        public readonly string $baseArea,
        public readonly string $baseRate,
        public readonly Money $baseAmount,
        public readonly Money $plcAmount,
        public readonly Money $chargeAmount,
        public readonly Money $subtotal,
        public readonly Money $discountAmount,
        public readonly Money $taxAmount,
        public readonly Money $finalAmount,
        public readonly array $lines,
    ) {}

    /**
     * Money columns for the `bookings` row.
     *
     * @return array<string, string>
     */
    public function toBookingAttributes(): array
    {
        return [
            'base_area' => $this->baseArea,
            'base_rate' => $this->baseRate,
            'base_amount' => $this->baseAmount->store(),
            'plc_amount' => $this->plcAmount->store(),
            'charge_amount' => $this->chargeAmount->store(),
            'subtotal' => $this->subtotal->store(),
            'discount_amount' => $this->discountAmount->store(),
            'tax_amount' => $this->taxAmount->store(),
            'final_amount' => $this->finalAmount->store(),
        ];
    }

    /**
     * Frozen breakdown stored in `bookings.pricing_snapshot` on confirm.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'calculated_at' => now()->toIso8601String(),
            'engine_version' => PricingEngineVersion::CURRENT,
            'base' => [
                'area' => $this->baseArea,
                'rate' => $this->baseRate,
                'amount' => $this->baseAmount->store(),
            ],
            'totals' => [
                'plc' => $this->plcAmount->store(),
                'charges' => $this->chargeAmount->store(),
                'subtotal' => $this->subtotal->store(),
                'discount' => $this->discountAmount->store(),
                'tax' => $this->taxAmount->store(),
                'final' => $this->finalAmount->store(),
            ],
            'lines' => array_map(fn (ResolvedPriceLine $l) => $l->toArray(), $this->lines),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge($this->toBookingAttributes(), [
            'lines' => array_map(fn (ResolvedPriceLine $l) => $l->toArray(), $this->lines),
        ]);
    }
}
