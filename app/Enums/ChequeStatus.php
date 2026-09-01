<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Basic cheque lifecycle (M7). Only set on payments whose payment mode is a
 * cheque mode. Bounce penalty / recovery is a later milestone.
 *
 *   PENDING ──▶ CLEARED   (payment → SUCCESS)
 *      └──────▶ BOUNCED   (payment → FAILED)
 */
enum ChequeStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Cleared = 'cleared';
    case Bounced = 'bounced';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Cleared => 'Cleared',
            self::Bounced => 'Bounced',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Cleared => 'success',
            self::Bounced => 'danger',
        };
    }
}
