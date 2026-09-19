<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Ageing buckets for available (unsold) plot inventory (M11.3), computed from
 * `days since plots.created_at`. Shared boundaries: 30 / 60 / 90 / 180 days.
 */
enum AgingBucket: string
{
    use HasLabel;

    case Days0To30 = '0-30';
    case Days31To60 = '31-60';
    case Days61To90 = '61-90';
    case Days91To180 = '91-180';
    case Days180Plus = '180+';

    public function label(): string
    {
        return match ($this) {
            self::Days0To30 => '0–30 days',
            self::Days31To60 => '31–60 days',
            self::Days61To90 => '61–90 days',
            self::Days91To180 => '91–180 days',
            self::Days180Plus => '180+ days',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Days0To30 => 'warning',
            self::Days31To60 => 'warning',
            self::Days61To90 => 'danger',
            self::Days91To180 => 'danger',
            self::Days180Plus => 'danger',
        };
    }
}
