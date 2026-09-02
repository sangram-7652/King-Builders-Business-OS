<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Possession clearance categories (M10). Each possession case carries exactly
 * one clearance row per category.
 */
enum ClearanceCategory: string
{
    use HasLabel;

    case Financial = 'financial';
    case Document = 'document';
    case Legal = 'legal';
    case Site = 'site';

    public function label(): string
    {
        return match ($this) {
            self::Financial => 'Financial',
            self::Document => 'Document',
            self::Legal => 'Legal',
            self::Site => 'Site',
        };
    }
}
