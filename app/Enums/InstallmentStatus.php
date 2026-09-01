<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Derived state of one installment (M7). Recomputed from the ledger
 * (`amount` vs successful allocations) + the due date after every allocation
 * or reversal — never edited by hand, except WAIVED which is an explicit,
 * authorised decision.
 */
enum InstallmentStatus: string
{
    use HasLabel;

    case Upcoming = 'upcoming';
    case Due = 'due';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Waived = 'waived';

    public function label(): string
    {
        return match ($this) {
            self::Upcoming => 'Upcoming',
            self::Due => 'Due',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
            self::Overdue => 'Overdue',
            self::Waived => 'Waived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Upcoming => 'muted',
            self::Due => 'info',
            self::PartiallyPaid => 'warning',
            self::Paid => 'success',
            self::Overdue => 'danger',
            self::Waived => 'muted',
        };
    }

    /** Statuses that still owe money. */
    public function isOutstanding(): bool
    {
        return ! in_array($this, [self::Paid, self::Waived], true);
    }

    public function isSettled(): bool
    {
        return in_array($this, [self::Paid, self::Waived], true);
    }
}
