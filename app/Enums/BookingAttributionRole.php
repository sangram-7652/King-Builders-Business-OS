<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * A partner's role on a booking attribution (M14.2). Exactly one PRIMARY per
 * booking; any number of CO_BROKER rows. Shares always total 100%.
 */
enum BookingAttributionRole: string
{
    use HasLabel;

    case Primary = 'primary';
    case CoBroker = 'co_broker';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Primary',
            self::CoBroker => 'Co-broker',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Primary => 'brand',
            self::CoBroker => 'muted',
        };
    }
}
