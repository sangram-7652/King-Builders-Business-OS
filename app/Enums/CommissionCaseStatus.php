<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Lifecycle of a commission case (M14.4+).
 *
 *   PENDING_REVIEW ─▶ APPROVED ─▶ PARTIALLY_PAID ─▶ PAID
 *         │  ▲           │  ▲
 *         ▼  │           ▼  │
 *       ON_HOLD ────────────┘
 *   any non-paid state ─▶ CANCELLED   (attribution removed / booking cancelled before payout)
 *   APPROVED / *_PAID  ─▶ REVERSED    (booking cancelled after approval — M14.5 clawback)
 *
 * M14.4 only drives PENDING_REVIEW and CANCELLED; the review / hold / payout /
 * reversal transitions land in M14.5.
 */
enum CommissionCaseStatus: string
{
    use HasLabel;

    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case OnHold = 'on_hold';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::PendingReview => 'Pending review',
            self::Approved => 'Approved',
            self::OnHold => 'On hold',
            self::PartiallyPaid => 'Partially paid',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
            self::Reversed => 'Reversed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PendingReview => 'warning',
            self::Approved => 'brand',
            self::OnHold => 'muted',
            self::PartiallyPaid => 'info',
            self::Paid => 'success',
            self::Cancelled, self::Reversed => 'danger',
        };
    }

    /** @return array<value-of<self>, list<self>> */
    public static function transitionMap(): array
    {
        return [
            self::PendingReview->value => [self::Approved, self::OnHold, self::Cancelled],
            self::OnHold->value => [self::PendingReview, self::Approved, self::Cancelled],
            self::Approved->value => [self::OnHold, self::PartiallyPaid, self::Paid, self::Reversed],
            self::PartiallyPaid->value => [self::Paid, self::Reversed],
            self::Paid->value => [self::Reversed],
            self::Cancelled->value => [],
            self::Reversed->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitionMap()[$this->value], true);
    }

    public function isTerminal(): bool
    {
        return self::transitionMap()[$this->value] === [];
    }

    /** The calculation can still be recomputed (nothing downstream depends on it yet). */
    public function isRecalculable(): bool
    {
        return in_array($this, [self::PendingReview, self::OnHold], true);
    }

    /** A case that still counts as a live commission obligation. */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Cancelled, self::Reversed], true);
    }

    public function isPayable(): bool
    {
        return in_array($this, [self::Approved, self::PartiallyPaid], true);
    }
}
