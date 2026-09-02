<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Transfer request category (M10). NOMINEE_CHANGE does not move ownership — it
 * only updates the buyer's nominee record.
 */
enum TransferType: string
{
    use HasLabel;

    case OwnerChange = 'owner_change';
    case NomineeChange = 'nominee_change';
    case FamilyTransfer = 'family_transfer';
    case SaleTransfer = 'sale_transfer';
    case LegalTransfer = 'legal_transfer';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::OwnerChange => 'Owner change',
            self::NomineeChange => 'Nominee change',
            self::FamilyTransfer => 'Family transfer',
            self::SaleTransfer => 'Sale transfer',
            self::LegalTransfer => 'Legal transfer',
            self::Other => 'Other',
        };
    }

    /** Whether completing this transfer moves plot ownership to a new buyer. */
    public function movesOwnership(): bool
    {
        return $this !== self::NomineeChange;
    }
}
