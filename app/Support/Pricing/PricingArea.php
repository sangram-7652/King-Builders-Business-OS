<?php

declare(strict_types=1);

namespace App\Support\Pricing;

use App\Enums\Masters\AreaUnit;
use App\Exceptions\DomainException;
use App\Models\Plot;

/**
 * The booking PRICING QUANTITY — `bookings.base_area` — derived from a Plot.
 *
 * Booking rates are always ₹ / SQUARE FOOT (the base rate and every
 * per-sq-ft PLC / charge / discount / tax line). A plot's own area may be in
 * any {@see AreaUnit} (sq ft, sq yd, sq m, acre, hectare), so before it can
 * be multiplied by a rate it is converted to square feet here:
 *
 *   base_area (sq ft) = plot.area × AreaUnit::squareFeetFactor()
 *
 * e.g. 50 sq yd → 450 sq ft → × ₹2,000 → ₹9,00,000.
 *
 * The plot's own `area` / `area_unit` are never modified — only the booking
 * stores the normalised figure. The result is rounded HALF-UP to
 * {@see self::SCALE} decimal places, the precision of `bookings.base_area`
 * (DECIMAL(15,4)), so the value the preview uses, the value that is stored
 * and the value confirmation recalculates from are always the SAME string.
 *
 * bcmath only — never float arithmetic.
 */
final class PricingArea
{
    /** Decimal places of `bookings.base_area` / every pricing input. */
    public const SCALE = 4;

    /** The normalised (sq ft) pricing quantity for a plot. */
    public static function fromPlot(Plot $plot): string
    {
        $unit = $plot->area_unit instanceof AreaUnit ? $plot->area_unit : AreaUnit::from((string) $plot->area_unit);

        return self::toSquareFeet((string) $plot->area, $unit);
    }

    public static function toSquareFeet(string $area, AreaUnit $unit): string
    {
        $area = trim($area);

        if (! preg_match('/^\d+(\.\d+)?$/', $area)) {
            throw new DomainException("[{$area}] is not a valid plot area.");
        }

        // Work well beyond the factors' own precision, then round once.
        $raw = bcmul($area, $unit->squareFeetFactor(), 12);

        return self::round($raw);
    }

    /** HALF-UP to {@see self::SCALE} places, for a non-negative decimal string. */
    public static function round(string $value): string
    {
        return bcadd($value, '0.'.str_repeat('0', self::SCALE).'5', self::SCALE);
    }

    /** Equal at pricing precision (e.g. a stored DECIMAL(15,4) vs a fresh derivation). */
    public static function equals(string $a, string $b): bool
    {
        return bccomp($a, $b, self::SCALE) === 0;
    }
}
