<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\User;
use App\Support\Reports\InventoryFilters;
use App\Support\Reports\InventoryReportData;
use App\Support\Reports\Kpi;
use App\Support\Reports\ReportFilterData;
use Illuminate\Pagination\LengthAwarePaginator;
use Throwable;

/**
 * Assembles the inventory report (M11.3).
 *
 * Pure orchestration over {@see InventoryAnalytics} — all SQL-aggregated
 * snapshots (no date window, no salesperson). Sections are isolated; a failing
 * query records an error and its slot renders "unavailable", never `0`.
 *
 * No caching: ~9 bounded aggregate queries + one paginated list, all on
 * indexed columns.
 */
class InventoryReportService
{
    public function __construct(private readonly InventoryAnalytics $inventory) {}

    public function build(ReportFilterData $filters, InventoryFilters $extras, User $user): InventoryReportData
    {
        /** @var array<string, string> $errors */
        $errors = [];
        $safe = function (string $section, callable $fn, mixed $fallback) use (&$errors) {
            try {
                return $fn();
            } catch (Throwable $e) {
                report($e);
                $errors[$section] = 'Could not load '.lcfirst($section).'.';

                return $fallback;
            }
        };

        $kpiData = $safe('Inventory KPIs', fn () => $this->inventory->inventoryKpis($filters, $extras), null);
        $distribution = $safe('Status distribution', fn () => $this->inventory->statusDistribution($filters, $extras), []);
        $projectInv = $safe('Project inventory', fn () => $this->inventory->projectInventory($filters, $extras), []);
        $blockInv = $safe('Block inventory', fn () => $this->inventory->blockInventory($filters, $extras), []);
        $ageing = $safe('Inventory ageing', fn () => $this->inventory->ageing($filters, $extras), []);
        $priceBands = $safe('Price analytics', fn () => $this->inventory->priceBands($filters), []);
        $sizeBands = $safe('Size analytics', fn () => $this->inventory->sizeBands($filters, $extras), []);

        $available = $safe(
            'Available plots',
            fn () => $this->inventory->availablePlots($filters, $extras, 20),
            new LengthAwarePaginator([], 0, 20),
        );
        $available->appends($filters->toQueryString() + $extras->toQueryString());

        $err = fn (string $s) => $errors[$s] ?? null;
        $k = fn (string $key) => $kpiData[$key] ?? null;

        $kpis = [
            new Kpi('total_inventory', 'Total inventory', $k('total'), 'number', error: $err('Inventory KPIs')),
            new Kpi('available', 'Available', $k('available'), 'number', error: $err('Inventory KPIs')),
            new Kpi('booked', 'Booked', $k('booked'), 'number', error: $err('Inventory KPIs')),
            new Kpi('registered', 'Registered', $k('registered'), 'number', error: $err('Inventory KPIs'), hint: 'Completed M9 registry'),
            new Kpi('possession_completed', 'Possession completed', $k('possession'), 'number', error: $err('Inventory KPIs')),
        ];

        return new InventoryReportData(
            kpis: $kpis,
            distribution: is_array($distribution) ? $distribution : [],
            projectInventory: $projectInv,
            blockInventory: $blockInv,
            ageing: is_array($ageing) ? $ageing : [],
            priceBands: $priceBands,
            sizeBands: $sizeBands,
            availablePlots: $available,
            filters: $filters,
            extras: $extras,
            priceSupported: collect($priceBands)->sum('bookings') > 0,
            errors: $errors,
        );
    }
}
