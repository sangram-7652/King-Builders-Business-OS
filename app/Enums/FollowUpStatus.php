<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Explicit state of a follow-up (M13.1).
 *
 * MISSED is set by the MarkMissedFollowUpsJob sweep when a
 * PENDING follow-up is still open past its due time — it never happens
 * implicitly on read. RESCHEDULED marks the *old* row when a new one supersedes
 * it (the chain is preserved via `rescheduled_from_id`, so history is intact).
 */
enum FollowUpStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Completed = 'completed';
    case Missed = 'missed';
    case Cancelled = 'cancelled';
    case Rescheduled = 'rescheduled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Completed',
            self::Missed => 'Missed',
            self::Cancelled => 'Cancelled',
            self::Rescheduled => 'Rescheduled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'info',
            self::Completed => 'success',
            self::Missed => 'danger',
            self::Cancelled => 'muted',
            self::Rescheduled => 'warning',
        };
    }

    /** Still needs action from the salesperson. */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    /** No further action possible. */
    public function isClosed(): bool
    {
        return ! $this->isOpen();
    }
}
