<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * The simplified, booking-level Registry status — replaces the old
 * RegistryCase / RegistryCaseStatus workflow as the user-facing concept
 * (RegistryCase itself is kept intact for historical data only; see
 * App\Actions\Registry\ChangeRegistryStatusAction).
 *
 *   PENDING ──▶ DONE ⇄ UNDONE
 *
 * Registry drives the booking's CURRENT plot: DONE → Sold, UNDONE → Booked
 * (PENDING is the initial Booked state). UNDONE is "a completed registry
 * that has been reversed" — kept distinct from PENDING so the history of a
 * reversal is never mistaken for a registry that simply never happened.
 */
enum RegistryStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Done = 'done';
    case Undone = 'undone';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Done => 'Done',
            self::Undone => 'Undone',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Done => 'success',
            self::Undone => 'danger',
        };
    }

    /** @return array<value-of<self>, list<self>> */
    public static function transitionMap(): array
    {
        return [
            self::Pending->value => [self::Done],
            self::Done->value => [self::Undone],
            self::Undone->value => [self::Done],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitionMap()[$this->value], true);
    }

    /** The single action the operator can take from this state. */
    public function nextAction(): self
    {
        return self::transitionMap()[$this->value][0];
    }

    public function isDone(): bool
    {
        return $this === self::Done;
    }
}
