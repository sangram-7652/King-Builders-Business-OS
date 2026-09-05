<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * How a commission payout was settled (M14.5). Operational tracking only — this
 * is NOT an accounting ledger and carries no GST / TDS.
 */
enum CommissionPayoutMethod: string
{
    use HasLabel;

    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case Adjustment = 'adjustment';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank transfer',
            self::Cheque => 'Cheque',
            self::Adjustment => 'Adjustment / set-off',
            self::Other => 'Other',
        };
    }
}
