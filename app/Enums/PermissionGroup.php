<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * UI grouping for the role permission matrix. Order here is the display order.
 */
enum PermissionGroup: string
{
    use HasLabel;

    case Sales = 'sales';
    case Inventory = 'inventory';
    case Finance = 'finance';
    case Collections = 'collections';
    case Operations = 'operations';
    case Associates = 'associates';
    case Commission = 'commission';
    case Communication = 'communication';
    case Reports = 'reports';
    case Administration = 'administration';
    case MasterData = 'master_data';
    case Settings = 'settings';

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'Sales',
            self::Inventory => 'Inventory',
            self::Finance => 'Finance',
            self::Collections => 'Collections',
            self::Operations => 'Operations',
            self::Associates => 'Channel Partners',
            self::Commission => 'Commission',
            self::Communication => 'Communications',
            self::Reports => 'Reports',
            self::Administration => 'Administration',
            self::MasterData => 'Master Data',
            self::Settings => 'Settings',
        };
    }
}
