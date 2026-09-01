<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Lifecycle state of a Lead.
 *
 * The transition map is the single source of truth. CONVERTED is a *controlled*
 * state: it is never a valid target of the generic ChangeLeadStatus action and
 * can only be reached through ConvertLeadToBuyer.
 */
enum LeadStatus: string
{
    use HasLabel;

    case New = 'new';
    case Contacted = 'contacted';
    case Interested = 'interested';
    case FollowUp = 'follow_up';
    case Qualified = 'qualified';
    case Converted = 'converted';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Interested => 'Interested',
            self::FollowUp => 'Follow-up',
            self::Qualified => 'Qualified',
            self::Converted => 'Converted',
            self::Lost => 'Lost',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'info',
            self::Contacted => 'brand',
            self::Interested => 'brand',
            self::FollowUp => 'warning',
            self::Qualified => 'success',
            self::Converted => 'success',
            self::Lost => 'danger',
        };
    }

    /**
     * Generic transitions available from the lead detail screen. QUALIFIED →
     * CONVERTED is deliberately absent — conversion is its own action.
     *
     * @return array<value-of<self>, list<self>>
     */
    public static function transitionMap(): array
    {
        return [
            self::New->value => [self::Contacted, self::Lost],
            self::Contacted->value => [self::Interested, self::FollowUp, self::Qualified, self::Lost],
            self::Interested->value => [self::FollowUp, self::Qualified, self::Lost],
            self::FollowUp->value => [self::Contacted, self::Interested, self::Qualified, self::Lost],
            self::Qualified->value => [self::FollowUp, self::Lost],
            self::Converted->value => [],
            self::Lost->value => [self::New, self::Contacted],
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

    public function isConverted(): bool
    {
        return $this === self::Converted;
    }

    public function canBeConverted(): bool
    {
        return $this === self::Qualified;
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Converted, self::Lost], true);
    }
}
