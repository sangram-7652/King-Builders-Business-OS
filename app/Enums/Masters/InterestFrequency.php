<?php

declare(strict_types=1);

namespace App\Enums\Masters;

use App\Enums\Concerns\HasLabel;

enum InterestFrequency: string
{
    use HasLabel;

    case Daily = 'daily';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case HalfYearly = 'half_yearly';
    case Annually = 'annually';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily',
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::HalfYearly => 'Half-yearly',
            self::Annually => 'Annually',
        };
    }
}
