<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Models\Plot;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Everything the inventory report blade needs (M11.3), assembled by
 * `InventoryReportService`.
 *
 * @phpstan-type RollupRow array{project:string, total:int, available:int, booked:int, registered:int, possession:int, sold_pct:float|null}
 */
final readonly class InventoryReportData
{
    /**
     * @param  list<Kpi>  $kpis
     * @param  array<string, int>  $distribution  PlotStatus value => count
     * @param  list<array<string, mixed>>  $projectInventory
     * @param  list<array<string, mixed>>  $blockInventory
     * @param  array<string, int>  $ageing  AgingBucket value => count
     * @param  list<array<string, mixed>>  $priceBands
     * @param  list<array<string, mixed>>  $sizeBands
     * @param  LengthAwarePaginator<int, Plot>  $availablePlots
     * @param  array<string, string>  $errors
     */
    public function __construct(
        public array $kpis,
        public array $distribution,
        public array $projectInventory,
        public array $blockInventory,
        public array $ageing,
        public array $priceBands,
        public array $sizeBands,
        public LengthAwarePaginator $availablePlots,
        public ReportFilterData $filters,
        public InventoryFilters $extras,
        public bool $priceSupported,
        public array $errors = [],
    ) {}

    public function kpi(string $key): ?Kpi
    {
        foreach ($this->kpis as $kpi) {
            if ($kpi->key === $key) {
                return $kpi;
            }
        }

        return null;
    }

    public function error(string $section): ?string
    {
        return $this->errors[$section] ?? null;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
