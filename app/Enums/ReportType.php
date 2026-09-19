<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\Reports\ReportExportPayload;

/**
 * The reports that expose a tabular export (M11.5).
 *
 * The executive dashboard (`overview`) is deliberately absent — it is a
 * visual/at-a-glance surface, not a table. Every case here maps to a report
 * whose service can produce a {@see ReportExportPayload}.
 */
enum ReportType: string
{
    case Sales = 'sales';
    case Inventory = 'inventory';
    case Mis = 'mis';

    public function title(): string
    {
        return match ($this) {
            self::Sales => 'Sales report',
            self::Inventory => 'Inventory report',
            self::Mis => 'Management MIS',
        };
    }

    public function screenRoute(): string
    {
        return 'reports.'.$this->value;
    }
}
