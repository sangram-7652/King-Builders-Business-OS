<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum UserStatus: string
{
    use HasLabel;

    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
        };
    }

    /** Badge variant for <x-ui.badge>. */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'muted',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
