<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * How a plot ownership period began (M10).
 *
 *   ALLOTMENT — the original M6 booking allotment
 *   TRANSFER  — created by a completed transfer request
 */
enum OwnershipType: string
{
    use HasLabel;

    case Allotment = 'allotment';
    case Transfer = 'transfer';

    public function label(): string
    {
        return match ($this) {
            self::Allotment => 'Allotment',
            self::Transfer => 'Transfer',
        };
    }
}
