<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Lifecycle of a Booking (M6). The transition map is the single source of
 * truth — a booking never jumps arbitrarily between states.
 *
 *   DRAFT ─▶ PENDING ─▶ CONFIRMED ─▶ CANCELLED
 *     └────────┴──────────┘  (both can be cancelled)
 *
 * There is no "un-cancel" and no CONFIRMED ─▶ PENDING: a confirmed booking has
 * a price snapshot and (usually) a BOOKED plot, so reopening it would rewrite
 * financial history. Cancellation is the only exit.
 */
enum BookingStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'muted',
            self::Pending => 'warning',
            self::Confirmed => 'success',
            self::Cancelled => 'danger',
        };
    }

    /**
     * @return array<value-of<self>, list<self>>
     */
    public static function transitionMap(): array
    {
        return [
            self::Draft->value => [self::Pending, self::Cancelled],
            self::Pending->value => [self::Confirmed, self::Cancelled],
            self::Confirmed->value => [self::Cancelled],
            self::Cancelled->value => [],
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

    /** A booking whose pricing / inventory effects are locked in. */
    public function isConfirmed(): bool
    {
        return $this === self::Confirmed;
    }

    /**
     * States that reserve the plot: no second booking may reach one of these
     * states for the same plot. Only CONFIRMED also flips Plot::status to
     * BOOKED — PENDING records intent without mutating M4 inventory.
     */
    public function reservesPlot(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed], true);
    }
}
