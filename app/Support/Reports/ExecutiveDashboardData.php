<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Services\Reports\ExecutiveDashboardService;

/**
 * Everything the executive dashboard blade needs (M11.2), assembled by
 * {@see ExecutiveDashboardService}.
 *
 * Each section is independent: if one analytics query fails, its slot carries an
 * error string and the rest of the dashboard still renders. The blade must
 * treat a failed section as "unavailable", never as zero.
 *
 * @phpstan-type ProjectPerfRow array{project_id:int, project:string, plots:int, booked:int, value:float, collected:float, outstanding:float}
 * @phpstan-type SalespersonRow array{user_id:int, name:string, bookings:int, value:float}
 * @phpstan-type RecentBookingRow array{id:int, booking_number:string, customer:string, plot:string, project:string, amount:float, date:string, status:string}
 * @phpstan-type AttentionItem array{key:string, label:string, count:int|null, url:?string, tone:string, error:?string}
 */
final readonly class ExecutiveDashboardData
{
    /**
     * @param  list<Kpi>  $kpis
     * @param  array<string, int>  $inventoryDistribution  PlotStatus value => count
     * @param  array<string, int>  $bookingStatusDistribution
     * @param  list<array<string, mixed>>  $projectPerformance
     * @param  list<array<string, mixed>>|null  $topSalespeople  null = not authorised to see it
     * @param  list<array<string, mixed>>  $recentBookings
     * @param  list<array<string, mixed>>  $attention
     * @param  array<string, string>  $collectionOverview  label => formatted / raw values
     * @param  list<string>  $errors
     */
    public function __construct(
        public array $kpis,
        public ChartSeries $salesSeries,
        public string $salesMetric,
        public array $collectionOverview,
        public array $inventoryDistribution,
        public array $bookingStatusDistribution,
        public array $projectPerformance,
        public ?array $topSalespeople,
        public array $recentBookings,
        public array $attention,
        public ReportFilterData $filters,
        public ReportFilterData $previousPeriod,
        public string $generatedAt,
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

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
