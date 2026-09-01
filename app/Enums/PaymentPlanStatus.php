<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Lifecycle of a payment plan (M7).
 *
 *   DRAFT ──▶ ACTIVE ──▶ COMPLETED
 *     └──────────┴──▶ CANCELLED
 *
 * Only an ACTIVE plan's installments accept allocations. COMPLETED is set
 * automatically once every installment is PAID or WAIVED.
 */
enum PaymentPlanStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'muted',
            self::Active => 'info',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    /** @return array<value-of<self>, list<self>> */
    public static function transitionMap(): array
    {
        return [
            self::Draft->value => [self::Active, self::Cancelled],
            self::Active->value => [self::Completed, self::Cancelled],
            self::Completed->value => [],
            self::Cancelled->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitionMap()[$this->value], true);
    }

    /** A plan whose installments are live for collection. */
    public function reservesBooking(): bool
    {
        return in_array($this, [self::Draft, self::Active], true);
    }
}
