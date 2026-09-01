<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum Gender: string
{
    use HasLabel;

    case Male = 'male';
    case Female = 'female';
    case Other = 'other';
    case Undisclosed = 'undisclosed';

    public function label(): string
    {
        return match ($this) {
            self::Male => 'Male',
            self::Female => 'Female',
            self::Other => 'Other',
            self::Undisclosed => 'Prefer not to say',
        };
    }
}
