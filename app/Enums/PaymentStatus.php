<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Lifecycle of a payment (M7).
 *
 *   PENDING ──▶ SUCCESS ──▶ REVERSED
 *      ├──▶ FAILED
 *      └──▶ CANCELLED
 *
 * ONLY `SUCCESS` payments contribute to paid / outstanding balances and to
 * installment allocations. A confirmed payment is never deleted — it moves to
 * `REVERSED` with a reason, and financial history stays intact.
 */
enum PaymentStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Success => 'Success',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Reversed => 'Reversed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Success => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'muted',
            self::Reversed => 'danger',
        };
    }

    /** @return array<value-of<self>, list<self>> */
    public static function transitionMap(): array
    {
        return [
            self::Pending->value => [self::Success, self::Failed, self::Cancelled],
            self::Success->value => [self::Reversed],
            self::Failed->value => [],
            self::Cancelled->value => [],
            self::Reversed->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitionMap()[$this->value], true);
    }

    /** The only state that moves money in the ledger. */
    public function countsTowardsBalance(): bool
    {
        return $this === self::Success;
    }

    public function isTerminal(): bool
    {
        return self::transitionMap()[$this->value] === [];
    }
}
