<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Lifecycle state of a Plot.
 *
 * The transition map is the single source of truth — nothing else in the
 * codebase compares status strings. TRANSFERRED is a *controlled* state: it is
 * never a valid target of the generic ChangePlotStatus action and can only be
 * reached through a dedicated transfer workflow added in a later milestone.
 */
enum PlotStatus: string
{
    use HasLabel;

    case Available = 'available';
    case Hold = 'hold';
    case Booked = 'booked';
    case Sold = 'sold';
    case Cancelled = 'cancelled';
    case Transferred = 'transferred';
    case PossessionCompleted = 'possession_completed';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Hold => 'On hold',
            self::Booked => 'Booked',
            self::Sold => 'Sold',
            self::Cancelled => 'Cancelled',
            self::Transferred => 'Transferred',
            self::PossessionCompleted => 'Possession completed',
        };
    }

    /** Badge variant for <x-ui.badge>. */
    public function color(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::Hold => 'warning',
            self::Booked => 'info',
            self::Sold => 'brand',
            self::Cancelled => 'danger',
            self::Transferred => 'muted',
            self::PossessionCompleted => 'success',
        };
    }

    /**
     * Generic, milestone-agnostic lifecycle transitions.
     *
     * HOLD → BOOKED, BOOKED → SOLD and BOOKED → CANCELLED are valid here but in
     * practice are driven by the Booking / Cancellation modules (M5+); M4 only
     * owns AVAILABLE ⇄ HOLD.
     *
     * @return array<value-of<self>, list<self>>
     */
    public static function transitionMap(): array
    {
        return [
            self::Available->value => [self::Hold],
            self::Hold->value => [self::Available, self::Booked],
            self::Booked->value => [self::Sold, self::Cancelled, self::PossessionCompleted],
            self::Sold->value => [self::PossessionCompleted],
            self::Cancelled->value => [],
            self::Transferred->value => [],
            self::PossessionCompleted->value => [],
        ];
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return self::transitionMap()[$this->value];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    public function isHold(): bool
    {
        return $this === self::Hold;
    }

    public function isAvailable(): bool
    {
        return $this === self::Available;
    }
}
