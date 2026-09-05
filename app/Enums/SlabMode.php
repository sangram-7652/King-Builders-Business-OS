<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * How a SLAB rule applies its brackets to the basis amount (M14.3).
 *
 *   WHOLE    — the single bracket the whole basis falls into sets one rate for
 *              the entire amount ("above ₹50L → 3% on the whole value")
 *   MARGINAL — each bracket's rate applies only to the portion of the basis
 *              inside that bracket, then the parts are summed (like income tax)
 */
enum SlabMode: string
{
    use HasLabel;

    case Whole = 'whole';
    case Marginal = 'marginal';

    public function label(): string
    {
        return match ($this) {
            self::Whole => 'Whole-amount (one bracket wins)',
            self::Marginal => 'Marginal (sum of tranches)',
        };
    }
}
