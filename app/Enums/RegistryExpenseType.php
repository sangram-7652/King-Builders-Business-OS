<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Registry expense categories (M9). Expense TRACKING only — not an accounting
 * ledger, and never posted to the M6/M7 booking financials.
 */
enum RegistryExpenseType: string
{
    use HasLabel;

    case StampDuty = 'stamp_duty';
    case RegistrationFee = 'registration_fee';
    case Documentation = 'documentation';
    case Processing = 'processing';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::StampDuty => 'Stamp duty',
            self::RegistrationFee => 'Registration fee',
            self::Documentation => 'Documentation',
            self::Processing => 'Processing',
            self::Other => 'Other',
        };
    }
}
