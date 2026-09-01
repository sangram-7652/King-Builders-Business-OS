<?php

declare(strict_types=1);

namespace App\Enums\Masters;

use App\Enums\Concerns\HasLabel;

/**
 * How a PLC (Preferred Location Charge) resolves to an amount. The pricing
 * engine that consumes this arrives in a later milestone (M6) — M2 only stores
 * the configuration cleanly.
 */
enum PlcCalculationType: string
{
    use HasLabel;

    case FixedAmount = 'fixed_amount';
    case PerSquareFoot = 'per_sq_ft';
    case Percentage = 'percentage';

    public function label(): string
    {
        return match ($this) {
            self::FixedAmount => 'Fixed amount',
            self::PerSquareFoot => 'Per square foot',
            self::Percentage => 'Percentage',
        };
    }

    /** Unit hint shown next to the value input. */
    public function valueSuffix(): string
    {
        return match ($this) {
            self::FixedAmount => '₹',
            self::PerSquareFoot => '₹ / sq ft',
            self::Percentage => '%',
        };
    }
}
