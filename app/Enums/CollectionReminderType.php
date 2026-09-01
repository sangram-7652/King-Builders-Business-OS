<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Internal collection reminder categories (M8). Foundation only — surfaced in
 * the app, no WhatsApp / SMS / Email integration.
 */
enum CollectionReminderType: string
{
    use HasLabel;

    case DueToday = 'due_today';
    case DueTomorrow = 'due_tomorrow';
    case Overdue = 'overdue';
    case PromiseDue = 'promise_due';
    case PromiseBroken = 'promise_broken';
    case ChequePending = 'cheque_pending';
    case ChequeBounced = 'cheque_bounced';

    public function label(): string
    {
        return match ($this) {
            self::DueToday => 'Installment due today',
            self::DueTomorrow => 'Installment due tomorrow',
            self::Overdue => 'Installment overdue',
            self::PromiseDue => 'Promise due',
            self::PromiseBroken => 'Promise broken',
            self::ChequePending => 'Cheque pending clearance',
            self::ChequeBounced => 'Cheque bounced',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DueToday, self::DueTomorrow, self::ChequePending => 'warning',
            self::PromiseDue => 'info',
            self::Overdue, self::PromiseBroken, self::ChequeBounced => 'danger',
        };
    }
}
