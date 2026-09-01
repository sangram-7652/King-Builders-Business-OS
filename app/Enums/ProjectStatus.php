<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Lifecycle state of a Project / Site.
 *
 * The allowed transitions are the single source of truth — nothing else in the
 * codebase should compare status strings directly. Reopening a CLOSED project is
 * deliberately NOT a transition here: it must be a controlled business action
 * added in a later milestone.
 */
enum ProjectStatus: string
{
    use HasLabel;

    case Planning = 'planning';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Planning => 'Planning',
            self::Active => 'Active',
            self::OnHold => 'On hold',
            self::Completed => 'Completed',
            self::Closed => 'Closed',
        };
    }

    /** Badge variant for <x-ui.badge>. */
    public function color(): string
    {
        return match ($this) {
            self::Planning => 'info',
            self::Active => 'success',
            self::OnHold => 'warning',
            self::Completed => 'brand',
            self::Closed => 'muted',
        };
    }

    /**
     * @return array<value-of<self>, list<self>>
     */
    public static function transitionMap(): array
    {
        return [
            self::Planning->value => [self::Active, self::OnHold],
            self::Active->value => [self::OnHold, self::Completed],
            self::OnHold->value => [self::Active, self::Closed],
            self::Completed->value => [self::Closed],
            self::Closed->value => [],
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
}
