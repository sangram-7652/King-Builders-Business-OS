<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * How a plot ownership period began (M10).
 *
 *   ALLOTMENT   — the original M6 booking allotment
 *   TRANSFER    — created by a completed ownership-moving transfer request
 *   PLOT_CHANGE — created by a completed plot transfer (same buyer, new plot)
 */
enum OwnershipType: string
{
    use HasLabel;

    case Allotment = 'allotment';
    case Transfer = 'transfer';
    case PlotChange = 'plot_change';

    public function label(): string
    {
        return match ($this) {
            self::Allotment => 'Allotment',
            self::Transfer => 'Transfer',
            self::PlotChange => 'Plot change',
        };
    }
}
