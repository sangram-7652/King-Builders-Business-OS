<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Possession clearance status (M10).
 *
 *   PENDING ─▶ CLEARED / REJECTED / WAIVED
 *
 * Nothing is ever waived automatically — WAIVED is an explicit, permissioned
 * decision that must carry a reason.
 */
enum ClearanceStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Cleared = 'cleared';
    case Rejected = 'rejected';
    case Waived = 'waived';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Cleared => 'Cleared',
            self::Rejected => 'Rejected',
            self::Waived => 'Waived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'muted',
            self::Cleared => 'success',
            self::Rejected => 'danger',
            self::Waived => 'warning',
        };
    }

    /** Counts as satisfied for possession readiness. */
    public function isSatisfied(): bool
    {
        return in_array($this, [self::Cleared, self::Waived], true);
    }
}
