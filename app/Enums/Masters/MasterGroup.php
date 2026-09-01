<?php

declare(strict_types=1);

namespace App\Enums\Masters;

use App\Enums\Concerns\HasLabel;

/**
 * Navigation grouping for the Master Data section (Settings → Master Data).
 * Order here is the display order in the sidebar and index.
 */
enum MasterGroup: string
{
    use HasLabel;

    case Property = 'property';
    case Finance = 'finance';
    case Location = 'location';
    case Documents = 'documents';
    case Operations = 'operations';

    public function label(): string
    {
        return match ($this) {
            self::Property => 'Property',
            self::Finance => 'Finance',
            self::Location => 'Location',
            self::Documents => 'Documents',
            self::Operations => 'Operations',
        };
    }
}
