<?php

declare(strict_types=1);

namespace App\Enums\Masters;

use App\Enums\Concerns\HasLabel;

/**
 * The calculation model for an interest rule. Installment / interest maths that
 * consume this belong to a later milestone — M2 only stores configuration.
 */
enum InterestType: string
{
    use HasLabel;

    case Simple = 'simple';
    case Compound = 'compound';
    case FlatRate = 'flat';

    public function label(): string
    {
        return match ($this) {
            self::Simple => 'Simple interest',
            self::Compound => 'Compound interest',
            self::FlatRate => 'Flat rate',
        };
    }
}
