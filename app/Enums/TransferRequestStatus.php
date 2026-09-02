<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Ownership / nominee transfer request workflow (M10).
 *
 *   DRAFT ─▶ SUBMITTED ─▶ UNDER_REVIEW ─▶ DOCUMENTS_PENDING ⇄ UNDER_REVIEW
 *                                     ─▶ APPROVED ─▶ COMPLETED
 *                                     ─▶ REJECTED
 *   any non-terminal state ─▶ CANCELLED
 *
 * COMPLETED is the only state that mutates ownership history, and it does so
 * transactionally.
 */
enum TransferRequestStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case DocumentsPending = 'documents_pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::DocumentsPending => 'Documents pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'muted',
            self::Submitted => 'info',
            self::UnderReview => 'warning',
            self::DocumentsPending => 'warning',
            self::Approved => 'brand',
            self::Rejected => 'danger',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }

    /** @return array<value-of<self>, list<self>> */
    public static function transitionMap(): array
    {
        return [
            self::Draft->value => [self::Submitted, self::Cancelled],
            self::Submitted->value => [self::UnderReview, self::Cancelled],
            self::UnderReview->value => [self::DocumentsPending, self::Approved, self::Rejected, self::Cancelled],
            self::DocumentsPending->value => [self::UnderReview, self::Cancelled],
            self::Approved->value => [self::Completed, self::Cancelled],
            self::Rejected->value => [],
            self::Completed->value => [],
            self::Cancelled->value => [],
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

    /** An open request that blocks a second concurrent transfer on the same booking. */
    public function isBlockingActive(): bool
    {
        return in_array($this, [
            self::Submitted, self::UnderReview, self::DocumentsPending, self::Approved,
        ], true);
    }
}
