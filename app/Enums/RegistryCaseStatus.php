<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Registry case workflow (M9).
 *
 *   NOT_STARTED ─▶ ELIGIBILITY_PENDING ⇄ READY ─▶ SCHEDULED ─▶ IN_PROCESS ─▶ COMPLETED
 *                          any active state ─▶ ON_HOLD ─▶ (resume)
 *                          any non-completed state ─▶ CANCELLED
 *
 * ELIGIBILITY_PENDING ⇄ READY is driven by the RegistryEligibilityService.
 */
enum RegistryCaseStatus: string
{
    use HasLabel;

    case NotStarted = 'not_started';
    case EligibilityPending = 'eligibility_pending';
    case Ready = 'ready';
    case Scheduled = 'scheduled';
    case InProcess = 'in_process';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case OnHold = 'on_hold';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::EligibilityPending => 'Eligibility pending',
            self::Ready => 'Ready',
            self::Scheduled => 'Scheduled',
            self::InProcess => 'In process',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::OnHold => 'On hold',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NotStarted, self::EligibilityPending => 'muted',
            self::Ready => 'info',
            self::Scheduled => 'warning',
            self::InProcess => 'brand',
            self::Completed => 'success',
            self::Cancelled => 'danger',
            self::OnHold => 'danger',
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

    /** States the eligibility service is allowed to flip between. */
    public function isEligibilityDriven(): bool
    {
        return in_array($this, [self::NotStarted, self::EligibilityPending, self::Ready], true);
    }
}
