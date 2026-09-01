<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Bounce-penalty lifecycle (M8) — assessment foundation only.
 *
 *   ASSESSED ─▶ APPROVED
 *       └──────▶ CANCELLED
 *
 * A penalty is an explicit, authorised, traceable record. It does NOT post to
 * the M7 booking financial total.
 */
enum PenaltyStatus: string
{
    use HasLabel;

    case Assessed = 'assessed';
    case Approved = 'approved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Assessed => 'Assessed',
            self::Approved => 'Approved',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Assessed => 'warning',
            self::Approved => 'success',
            self::Cancelled => 'muted',
        };
    }
}
