<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Lifecycle of a channel partner (M14). The transition map is the single
 * source of truth — a partner never jumps arbitrarily between states.
 *
 *   DRAFT ─▶ PENDING ─▶ ACTIVE ⇄ ON_HOLD
 *                         │        │
 *                         ▼        ▼
 *                     SUSPENDED ─▶ BLACKLISTED ─▶ INACTIVE
 *   (INACTIVE / DRAFT can be re-activated; nothing is truly terminal)
 *
 * Only an ACTIVE partner may receive NEW attribution. Historical attribution
 * and commission are never removed when a partner is suspended or blacklisted.
 */
enum PartnerStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case Pending = 'pending';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Suspended = 'suspended';
    case Blacklisted = 'blacklisted';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Pending => 'Pending approval',
            self::Active => 'Active',
            self::OnHold => 'On hold',
            self::Suspended => 'Suspended',
            self::Blacklisted => 'Blacklisted',
            self::Inactive => 'Inactive',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'muted',
            self::Pending => 'warning',
            self::Active => 'success',
            self::OnHold => 'warning',
            self::Suspended, self::Blacklisted => 'danger',
            self::Inactive => 'muted',
        };
    }

    /**
     * @return array<value-of<self>, list<self>>
     */
    public static function transitionMap(): array
    {
        return [
            self::Draft->value => [self::Pending, self::Active, self::Inactive],
            self::Pending->value => [self::Active, self::OnHold, self::Draft, self::Inactive],
            self::Active->value => [self::OnHold, self::Suspended, self::Blacklisted, self::Inactive],
            self::OnHold->value => [self::Active, self::Suspended, self::Blacklisted, self::Inactive],
            self::Suspended->value => [self::Active, self::Blacklisted, self::Inactive],
            self::Blacklisted->value => [self::Inactive],
            self::Inactive->value => [self::Active, self::Draft],
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

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    /** Only an active partner may be the target of a NEW booking attribution. */
    public function canReceiveAttribution(): bool
    {
        return $this === self::Active;
    }

    /** A partner still somewhere in the working pipeline (not retired / barred). */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Blacklisted, self::Inactive], true);
    }
}
