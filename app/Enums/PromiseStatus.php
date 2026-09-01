<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Promise-to-pay lifecycle (M8).
 *
 *   OPEN ─▶ KEPT       (an actual SUCCESS M7 payment fulfilled it)
 *     ├──▶ BROKEN      (promise_date passed, still unfulfilled)
 *     └──▶ CANCELLED   (explicitly cancelled by a collection user)
 *
 * A promise is NEVER a payment. Marking KEPT is driven by M7 truth, never by a
 * button click.
 */
enum PromiseStatus: string
{
    use HasLabel;

    case Open = 'open';
    case Kept = 'kept';
    case Broken = 'broken';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Kept => 'Kept',
            self::Broken => 'Broken',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'info',
            self::Kept => 'success',
            self::Broken => 'danger',
            self::Cancelled => 'muted',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
