<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Agreement workflow (M9).
 *
 *   DRAFT ─▶ PREPARED ─▶ SENT ─▶ SIGNED ─▶ APPROVED
 *     └────────┴──────────┴───────┴──▶ CANCELLED   (not after APPROVED)
 *
 * Every prepared/signed file is kept as a version — a signed agreement is never
 * overwritten. The agreement references the historical M6 booking terms; it
 * never alters the price snapshot.
 */
enum AgreementStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case Prepared = 'prepared';
    case Sent = 'sent';
    case Signed = 'signed';
    case Approved = 'approved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Prepared => 'Prepared',
            self::Sent => 'Sent',
            self::Signed => 'Signed',
            self::Approved => 'Approved',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'muted',
            self::Prepared => 'info',
            self::Sent => 'warning',
            self::Signed => 'brand',
            self::Approved => 'success',
            self::Cancelled => 'danger',
        };
    }

    /** @return array<value-of<self>, list<self>> */
    public static function transitionMap(): array
    {
        return [
            self::Draft->value => [self::Prepared, self::Cancelled],
            self::Prepared->value => [self::Sent, self::Signed, self::Cancelled],
            self::Sent->value => [self::Signed, self::Cancelled],
            self::Signed->value => [self::Approved, self::Cancelled],
            self::Approved->value => [],
            self::Cancelled->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitionMap()[$this->value], true);
    }

    public function isSignedOrLater(): bool
    {
        return in_array($this, [self::Signed, self::Approved], true);
    }
}
