<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Lifecycle of a collection case (M8) — a workflow state, NOT a financial one.
 * The money position always comes from M7.
 *
 *   OPEN ─▶ IN_PROGRESS ─▶ PROMISE_TO_PAY ─▶ RESOLVED
 *     └────────┴───────────────┴──▶ ESCALATED ─▶ RESOLVED
 *
 * A case auto-resolves when the booking's M7 outstanding reaches zero, and
 * re-opens if a later reversal/bounce brings outstanding back.
 */
enum CollectionCaseStatus: string
{
    use HasLabel;

    case Open = 'open';
    case InProgress = 'in_progress';
    case PromiseToPay = 'promise_to_pay';
    case Escalated = 'escalated';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In progress',
            self::PromiseToPay => 'Promise to pay',
            self::Escalated => 'Escalated',
            self::Resolved => 'Resolved',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'info',
            self::InProgress => 'warning',
            self::PromiseToPay => 'brand',
            self::Escalated => 'danger',
            self::Resolved => 'success',
        };
    }

    public function isOpen(): bool
    {
        return $this !== self::Resolved;
    }

    /** Manual transitions a user may pick (auto-transitions bypass this). */
    public static function assignableStatuses(): array
    {
        return [self::Open, self::InProgress, self::PromiseToPay, self::Escalated];
    }
}
