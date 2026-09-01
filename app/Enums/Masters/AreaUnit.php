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
}
