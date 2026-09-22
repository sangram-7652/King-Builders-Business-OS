<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * The simplified, booking-level Registry status — replaces the old
 * RegistryCase / RegistryCaseStatus workflow as the user-facing concept
 * (RegistryCase itself is kept intact for historical data only; see
 * App\Actions\Registry\MarkRegistryDoneAction).
 *
 *   PENDING ──▶ DONE
 *
 * DONE is terminal by design — there is no product requirement to revert a
 * completed registry, and the plot it drives to SOLD is likewise one-way in
 * the generic PlotStatus map.
 */
enum RegistryStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Done => 'Done',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Done => 'success',
        };
    }

    public function isDone(): bool
    {
        return $this === self::Done;
    }
}
