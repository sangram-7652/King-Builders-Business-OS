<?php

declare(strict_types=1);

namespace App\Support\Reports;

/**
 * Everything the sales report blade needs (M11.3), assembled by
 * `SalesReportService`.
 *
 * Each section is isolated: a failing query records an error string and the
 * section renders "unavailable" — never a fake zero.
 *
 * @phpstan-type ProjectRow array{project_id:int, project:string, bookings:int, value:float, average:float|null, sold_pct:float|null}
 * @phpstan-type BlockRow array{block_id:int, project_id:int, block:string, project:string, total:int, available:int, booked:int, value:float, sold_pct:float|null}
 * @phpstan-type PersonRow array{user_id:int, name:string, leads:int, bookings:int, value:float, conversion:float|null}
 */
final readonly class SalesReportData
{
    /**
     * @param  list<Kpi>  $kpis
     * @param  list<array<string, mixed>>  $projectSales
     * @param  list<array<string, mixed>>  $blockSales
     * @param  list<array<string, mixed>>  $salespeople
     * @param  array{bookings:int, days:int, per_day:float, per_week:float}  $velocity
     * @param  array<string, string>  $errors  section => message
     */
    public function __construct(
        public array $kpis,
        public ChartSeries $trend,
        public string $trendMetric,
        public array $projectSales,
        public array $blockSales,
        public array $salespeople,
        public string $salespeopleSort,
        public bool $salespeopleScoped,
        public array $velocity,
        public string $statusLabel,
        public ReportFilterData $filters,
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
