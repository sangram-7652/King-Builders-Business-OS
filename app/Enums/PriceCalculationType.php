<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;
use App\Enums\Masters\PlcCalculationType;

/**
 * How a single price line resolves to an amount (M6). Shared by PLC types,
 * charge types, discounts and tax lines so the engine has one code path.
 *
 *   FIXED        amount = rate                       (rate is a rupee figure)
 *   PERCENTAGE   amount = rate% of the line's base   (base depends on the component)
 *   PER_SQFT     amount = rate × plot area (sq ft)
 */
enum PriceCalculationType: string
{
    use HasLabel;

    case Fixed = 'fixed';
    case Percentage = 'percentage';
    case PerSqft = 'per_sqft';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed amount',
            self::Percentage => 'Percentage',
            self::PerSqft => 'Per sq ft',
        };
    }

    /** Bridge from the M2 PLC master enum to this shared one. */
    public static function fromPlc(PlcCalculationType $type): self
    {
        return match ($type) {
            PlcCalculationType::FixedAmount => self::Fixed,
            PlcCalculationType::Percentage => self::Percentage,
            PlcCalculationType::PerSquareFoot => self::PerSqft,
        };
    }
}
