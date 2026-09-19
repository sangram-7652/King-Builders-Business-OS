<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * One row type in a promoter's financial ledger (Promoter Advance).
 *
 *   AdvanceGiven               — advance paid to the promoter (balance +)
 *   AdvanceRefunded            — manual reduction / refund (balance -)
 *   Commission                 — commission earned on a booking; carries gross,
 *                                 advance-adjusted and payable together
 *                                 (balance - by the adjusted amount)
 *   AdvanceAdjustmentReversed  — compensates a Commission row's adjustment when
 *                                 that commission is cancelled / reversed / or
 *                                 superseded by a recalculation (balance +)
 *   CommissionPayoutRecorded   — informational mirror of a recorded payout
 *   CommissionPayoutVoided     — informational mirror of a voided payout
 */
enum PromoterLedgerEntryType: string
{
    use HasLabel;

    case AdvanceGiven = 'advance_given';
    case AdvanceRefunded = 'advance_refunded';
    case Commission = 'commission';
    case AdvanceAdjustmentReversed = 'advance_adjustment_reversed';
    case CommissionPayoutRecorded = 'commission_payout_recorded';
    case CommissionPayoutVoided = 'commission_payout_voided';

    public function label(): string
    {
        return match ($this) {
            self::AdvanceGiven => 'Advance given',
            self::AdvanceRefunded => 'Advance refunded',
            self::Commission => 'Commission',
            self::AdvanceAdjustmentReversed => 'Advance adjustment reversed',
            self::CommissionPayoutRecorded => 'Commission payout',
            self::CommissionPayoutVoided => 'Commission payout voided',
        };
    }

    /** Whether this row type moves the promoter's advance balance. */
    public function affectsAdvanceBalance(): bool
    {
        return $this !== self::CommissionPayoutRecorded && $this !== self::CommissionPayoutVoided;
    }
}
