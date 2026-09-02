<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Possession case workflow (M10).
 *
 *   NOT_STARTED ─▶ ELIGIBILITY_PENDING ⇄ READY ─▶ SCHEDULED ─▶ INSPECTION
 *                     ─▶ READY_FOR_HANDOVER ─▶ COMPLETED
 *                     any active state ─▶ ON_HOLD ─▶ (resume)
 *                     any non-completed state ─▶ CANCELLED
 *
 * ELIGIBILITY_PENDING ⇄ READY is driven by the PossessionEligibilityService.
 */
enum PossessionCaseStatus: string
{
    use HasLabel;

    case NotStarted = 'not_started';
    case EligibilityPending = 'eligibility_pending';
    case Ready = 'ready';
    case Scheduled = 'scheduled';
    case Inspection = 'inspection';
    case ReadyForHandover = 'ready_for_handover';
    case Completed = 'completed';
    case OnHold = 'on_hold';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::EligibilityPending => 'Eligibility pending',
            self::Ready => 'Ready',
            self::Scheduled => 'Scheduled',
            self::Inspection => 'Inspection',
            self::ReadyForHandover => 'Ready for handover',
            self::Completed => 'Completed',
            self::OnHold => 'On hold',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NotStarted, self::EligibilityPending => 'muted',
            self::Ready => 'info',
            self::Scheduled => 'warning',
            self::Inspection => 'brand',
            self::ReadyForHandover => 'info',
            self::Completed => 'success',
            self::OnHold => 'danger',
            self::Cancelled => 'danger',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    /** States the eligibility engine is allowed to flip between. */
    public function isEligibilityDriven(): bool
    {
        return in_array($this, [self::NotStarted, self::EligibilityPending, self::Ready], true);
    }
}
