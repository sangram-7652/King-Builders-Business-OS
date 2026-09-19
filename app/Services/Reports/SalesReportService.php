<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\BookingStatus;
use App\Models\User;
use App\Support\Reports\ChartSeries;
use App\Support\Reports\Kpi;
use App\Support\Reports\PreviousPeriod;
use App\Support\Reports\ReportFilterData;
use App\Support\Reports\SalesReportData;
use Throwable;

/**
 * Assembles the sales report (M11.3).
 *
 * Pure orchestration over {@see SalesAnalytics} (all SQL-aggregated) plus the
 * previous-period comparison from {@see PreviousPeriod}. Each section is
 * isolated — a failing query records an error and yields `null`, never a fake
 * `0`. No caching: the page is ~10 bounded aggregate queries on indexed
 * columns; if that changes, this method is the single wrap point and a cache
 * key MUST combine the user id with `md5` of the filter query string.
 */
class SalesReportService
{
    public function __construct(private readonly SalesAnalytics $sales) {}

    public function build(ReportFilterData $filters, User $user, string $trendMetric = 'value', string $spSort = 'value'): SalesReportData
    {
        $trendMetric = in_array($trendMetric, ['value', 'bookings'], true) ? $trendMetric : 'value';
        $spSort = in_array($spSort, ['value', 'bookings'], true) ? $spSort : 'value';
        $previous = PreviousPeriod::for($filters);

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

        $now = $safe('Sales KPIs', fn () => $this->sales->salesKpis($filters), null);
        $prev = $now === null ? null : $safe('Sales KPIs', fn () => $this->sales->salesKpis($previous), null);

        $trend = $safe('Sales trend', fn () => $this->sales->timeSeries($filters, $trendMetric), ChartSeries::failed('Could not load the sales trend.'));
        $projectSales = $safe('Project-wise sales', fn () => $this->sales->projectSales($filters), []);
        $blockSales = $safe('Block-wise sales', fn () => $this->sales->blockSales($filters), []);
        $velocity = $safe('Booking velocity', fn () => $this->sales->bookingVelocity($filters), ['bookings' => 0, 'days' => 1, 'per_day' => 0.0, 'per_week' => 0.0]);

        $salespeople = $safe('Salesperson performance', fn () => $this->sales->salespersonPerformance($filters, null, $spSort), []);

        $err = fn (string $s) => $errors[$s] ?? null;
        $growth = new Kpi('_g', '_', $now['bookings'] ?? null, 'number', previous: $prev['bookings'] ?? null);

        $kpis = [
            new Kpi('total_bookings', 'Bookings', $now['bookings'] ?? null, 'number', previous: $prev['bookings'] ?? null, error: $err('Sales KPIs')),
            new Kpi('booking_value', 'Booking value', $now['value'] ?? null, 'currency', previous: $prev['value'] ?? null, error: $err('Sales KPIs')),
            new Kpi('avg_booking_value', 'Avg booking value', $now['average'] ?? null, 'currency', previous: $prev['average'] ?? null, error: $err('Sales KPIs')),
            new Kpi('booking_growth', 'Booking growth', $growth->delta(), 'percent', error: $err('Sales KPIs'), hint: 'vs previous period'),
        ];

        return new SalesReportData(
            kpis: $kpis,
            trend: $trend,
            trendMetric: $trendMetric,
            projectSales: $projectSales,
            blockSales: $blockSales,
            salespeople: $salespeople,
            salespeopleSort: $spSort,
            velocity: $velocity,
            statusLabel: ($filters->bookingStatus ?? BookingStatus::Confirmed)->label(),
            filters: $filters,
            errors: $errors,
        );
    }
}
