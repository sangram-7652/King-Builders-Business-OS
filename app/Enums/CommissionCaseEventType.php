<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Append-only commission case timeline (M14.4+). Reuses the M5/M8/M9 activity
 * pattern. M14.4 writes `generated` / `recalculated` / `cancelled`; the review,
 * hold, payout and reversal events land in M14.5.
 */
enum CommissionCaseEventType: string
{
    use HasLabel;

    case Generated = 'generated';
    case Recalculated = 'recalculated';
    case SubmittedForReview = 'submitted_for_review';
    case Approved = 'approved';
    case PutOnHold = 'put_on_hold';
    case Resumed = 'resumed';
    case PayoutRecorded = 'payout_recorded';
    case PayoutVoided = 'payout_voided';
    case FullyPaid = 'fully_paid';
    case Cancelled = 'cancelled';
    case Reversed = 'reversed';
    case NoteAdded = 'note_added';

    public function label(): string
    {
        return match ($this) {
            self::Generated => 'Commission generated',
            self::Recalculated => 'Commission recalculated',
            self::SubmittedForReview => 'Submitted for review',
            self::Approved => 'Approved',
            self::PutOnHold => 'Put on hold',
            self::Resumed => 'Resumed',
            self::PayoutRecorded => 'Payout recorded',
            self::PayoutVoided => 'Payout voided',
            self::FullyPaid => 'Fully paid',
            self::Cancelled => 'Cancelled',
            self::Reversed => 'Reversed',
            self::NoteAdded => 'Note added',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Generated, self::Recalculated => 'layers',
            self::PayoutRecorded, self::PayoutVoided, self::FullyPaid => 'inbox',
            self::Cancelled, self::Reversed => 'inbox',
            default => 'clock',
        };
    }
}
