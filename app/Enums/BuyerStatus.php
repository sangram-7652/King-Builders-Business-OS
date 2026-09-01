<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum BuyerStatus: string
{
    use HasLabel;

    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Archived => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'muted',
            self::Archived => 'warning',
        };
    }

    /**
     * @return array<value-of<self>, list<self>>
     */
    public static function transitionMap(): array
    {
        return [
            self::Active->value => [self::Inactive, self::Archived],
            self::Inactive->value => [self::Active, self::Archived],
            self::Archived->value => [self::Active],
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
}
