<?php

declare(strict_types=1);

namespace App\Enums\Masters;

use App\Enums\Concerns\HasLabel;

enum AreaUnit: string
{
    use HasLabel;

    case SquareFeet = 'sq_ft';
    case SquareYards = 'sq_yd';
    case SquareMetres = 'sq_m';
    case Acre = 'acre';
    case Hectare = 'hectare';

    public function label(): string
    {
        return match ($this) {
            self::SquareFeet => 'Square feet (sq ft)',
            self::SquareYards => 'Square yards (sq yd)',
            self::SquareMetres => 'Square metres (sq m)',
            self::Acre => 'Acre',
            self::Hectare => 'Hectare',
        };
    }

    public function abbreviation(): string
    {
        return match ($this) {
            self::SquareFeet => 'sq ft',
            self::SquareYards => 'sq yd',
            self::SquareMetres => 'sq m',
            self::Acre => 'acre',
            self::Hectare => 'ha',
        };
    }

    /**
     * How many SQUARE FEET one unit of this area unit is — the exact factors
     * used to normalise a plot's area into the booking's pricing quantity
     * (booking rates are ₹ / sq ft; see App\Support\Pricing\PricingArea).
     *
     * Returned as a decimal STRING for bcmath — never a float.
     */
    public function squareFeetFactor(): string
    {
        return match ($this) {
            self::SquareFeet => '1',
            self::SquareYards => '9',
            self::SquareMetres => '10.7639104167',
            self::Acre => '43560',
            self::Hectare => '107639.104167',
        };
    }
}
