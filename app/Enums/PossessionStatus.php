<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * The simplified, booking-level Possession status — replaces the old
 * PossessionCase / PossessionCaseStatus / PossessionEligibilityService
 * workflow as the user-facing concept (PossessionCase itself is kept intact
 * for historical data only; see App\Actions\Possession\MarkPossessionDoneAction).
 *
 *   PENDING ──▶ DONE
 *
 * DONE is terminal by design — there is no product requirement to revert a
 * completed possession. Unlike Registry, marking Possession Done NEVER
 * changes the plot's status: Plot status is driven exclusively by Registry
 * (Pending → Booked, Done → Sold). Possession is a separate operational
 * milestone (the physical handover), tracked independently.
 */
enum PossessionStatus: string
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
