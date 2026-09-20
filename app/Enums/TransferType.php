<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Transfer request category (M10). NOMINEE_CHANGE does not move ownership — it
 * only updates the buyer's nominee record. PLOT_TRANSFER does not move
 * ownership either — the buyer stays the same; only the booking's plot
 * changes (see PlotTransferService). Never confuse it with the
 * ownership-moving types below.
 */
enum TransferType: string
{
    use HasLabel;

    case OwnerChange = 'owner_change';
    case NomineeChange = 'nominee_change';
    case FamilyTransfer = 'family_transfer';
    case SaleTransfer = 'sale_transfer';
    case LegalTransfer = 'legal_transfer';
    case PlotTransfer = 'plot_transfer';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::OwnerChange => 'Owner change',
            self::NomineeChange => 'Nominee change',
            self::FamilyTransfer => 'Family transfer',
            self::SaleTransfer => 'Sale transfer',
            self::LegalTransfer => 'Legal transfer',
            self::PlotTransfer => 'Plot transfer / plot change',
            self::Other => 'Other',
        };
    }

    /** Whether completing this transfer moves plot ownership to a new buyer. */
    public function movesOwnership(): bool
    {
        return $this !== self::NomineeChange && $this !== self::PlotTransfer;
    }

    /** Whether completing this transfer changes which plot the booking sits on. */
    public function movesPlot(): bool
    {
        return $this === self::PlotTransfer;
    }
}
