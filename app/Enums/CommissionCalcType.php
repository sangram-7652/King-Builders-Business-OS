<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * How a commission rule (or a single slab) turns its input amount into a
 * commission figure (M14.3).
 *
 *   PERCENTAGE — `rate`% of the basis amount
 *   FIXED      — a flat `flat_amount`, independent of the basis
 *   SLAB       — tiered brackets ({@see CommissionSlab}); rules only, not slabs
 */
enum CommissionCalcType: string
{
    use HasLabel;

    case Percentage = 'percentage';
    case Fixed = 'fixed';
    case Slab = 'slab';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage of basis',
            self::Fixed => 'Fixed amount',
            self::Slab => 'Slab / tiered',
        };
    }
}
