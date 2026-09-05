<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Lifecycle of an outbound communication (M16). The transition map is the
 * single source of truth.
 *
 *   PENDING ─▶ QUEUED ─▶ SENDING ─▶ SENT ─▶ DELIVERED
 *                            └────────┴──────▶ FAILED
 *   PENDING / QUEUED ─▶ CANCELLED
 *
 * SENT means the provider accepted the message. DELIVERED is set ONLY when the
 * provider actually reports delivery (a webhook) — never inferred.
 */
enum CommunicationStatus: string
{
    use HasLabel;

    case Pending = 'pending';
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Queued => 'Queued',
            self::Sending => 'Sending',
            self::Sent => 'Sent',
            self::Delivered => 'Delivered',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending, self::Queued => 'muted',
            self::Sending => 'info',
            self::Sent => 'brand',
            self::Delivered => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'warning',
        };
    }

    /** @return array<value-of<self>, list<self>> */
    public static function transitionMap(): array
    {
        return [
            self::Pending->value => [self::Queued, self::Cancelled],
            self::Queued->value => [self::Sending, self::Cancelled],
            self::Sending->value => [self::Sent, self::Failed],
            self::Sent->value => [self::Delivered, self::Failed],
            self::Delivered->value => [],
            self::Failed->value => [self::Queued], // a manual retry re-queues
            self::Cancelled->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitionMap()[$this->value], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Cancelled], true);
    }

    /** The provider accepted the message (or better). */
    public function isAccepted(): bool
    {
        return in_array($this, [self::Sent, self::Delivered], true);
    }

    public function isRetryable(): bool
    {
        return $this === self::Failed;
    }
}
