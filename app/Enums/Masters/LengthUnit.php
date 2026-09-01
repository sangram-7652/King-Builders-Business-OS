<?php

declare(strict_types=1);

namespace App\Enums\Masters;

use App\Enums\Concerns\HasLabel;

enum LengthUnit: string
{
    use HasLabel;

    case Feet = 'ft';
    case Metres = 'm';
    case Yards = 'yd';

    public function label(): string
    {
        return match ($this) {
            self::Feet => 'Feet (ft)',
            self::Metres => 'Metres (m)',
            self::Yards => 'Yards (yd)',
        };
    }

    public function abbreviation(): string
    {
        return $this->value;
    }
}
