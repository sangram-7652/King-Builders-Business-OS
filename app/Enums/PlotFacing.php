<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Compass facing of a plot. Optional — many plots are recorded without one.
 */
enum PlotFacing: string
{
    use HasLabel;

    case North = 'north';
    case South = 'south';
    case East = 'east';
    case West = 'west';
    case NorthEast = 'north_east';
    case NorthWest = 'north_west';
    case SouthEast = 'south_east';
    case SouthWest = 'south_west';

    public function label(): string
    {
        return match ($this) {
            self::North => 'North',
            self::South => 'South',
            self::East => 'East',
            self::West => 'West',
            self::NorthEast => 'North-East',
            self::NorthWest => 'North-West',
            self::SouthEast => 'South-East',
            self::SouthWest => 'South-West',
        };
    }
}
