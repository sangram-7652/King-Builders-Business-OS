<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * State of a recorded commission payout (M14.5). A voided payout is kept for
 * the audit trail — never deleted — and stops counting toward the paid total.
 */
enum CommissionPayoutStatus: string
{
    use HasLabel;

    case Recorded = 'recorded';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Recorded => 'Recorded',
            self::Voided => 'Voided',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Recorded => 'success',
            self::Voided => 'muted',
        };
    }
}
