<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\PlotStatus;
use App\Models\User;
use App\Support\Reports\ChartSeries;
use App\Support\Reports\ExecutiveDashboardData;
use App\Support\Reports\Kpi;
use App\Support\Reports\PreviousPeriod;
use App\Support\Reports\ReportFilterData;
use Throwable;

/**
 * Assembles the executive dashboard (M11.2).
 *
 * Pure orchestration: it delegates every figure to a focused analytics service
 * (all SQL-aggregated) and computes the previous-period comparison. Each
 * section is isolated — a failing query records an error and yields a `null`
 * value, never a fake `0`, so the rest of the dashboard still renders.
 *
 * Caching: not used. The dashboard runs ~15 bounded aggregate queries against
 * indexed columns (single-digit ms on realistic data). If that changes, this
 * `build()` method is the single wrap point; a cache key MUST combine the user
 * id (RBAC scope) with `md5(json_encode($filters->toQueryString()))`.
 */
class ExecutiveDashboardService
{
    public function __construct(
        private readonly SalesAnalytics $sales,
        private readonly CollectionAnalytics $collection,
        private readonly InventoryAnalytics $inventory,
        private readonly LeadAnalytics $leads,
        private readonly OperationsAnalytics $operations,
    ) {}

    public function build(ReportFilterData $filters, User $user, string $salesMetric = 'value'): ExecutiveDashboardData
    {
        $salesMetric = in_array($salesMetric, ['value', 'bookings'], true) ? $salesMetric : 'value';
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

        // --- Sections (each isolated) --------------------------------------
        $salesNow = $safe('Sales', fn () => $this->sales->bookingSummary($filters), null);
        $salesPrev = $salesNow === null ? null : $safe('Sales', fn () => $this->sales->bookingSummary($previous), null);

        $collectionNow = $safe('Collections', fn () => $this->collection->summary($filters), null);
        $collectionPrev = $collectionNow === null ? null : $safe('Collections', fn () => $this->collection->summary($previous), null);

        $leadsNow = $safe('Leads', fn () => $this->leads->summary($filters), null);
        $leadsPrev = $leadsNow === null ? null : $safe('Leads', fn () => $this->leads->summary($previous), null);

        $inventoryDist = $safe('Inventory', fn () => $this->inventory->statusDistribution($filters), null);
        $totalProjects = $safe('Projects', fn () => $this->inventory->totalProjects($filters), null);

        $registryPending = $safe('Registry', fn () => $this->operations->registryPending($filters), null);
        $possessionPending = $safe('Possession', fn () => $this->operations->possessionPending($filters), null);
        $transferPending = $safe('Transfers', fn () => $this->operations->transferPending($filters), null);
        $transferApproval = $safe('Transfers', fn () => $this->operations->transferAwaitingApproval($filters), null);
        $docsPending = $safe('Documents', fn () => $this->operations->documentsAwaitingVerification($filters), null);
        $overdueBookings = $safe('Overdue customers', fn () => $this->collection->overdueBookingCount($filters), null);

        $bookingStatus = $safe('Booking status', fn () => $this->sales->statusDistribution($filters), []);
        $salesSeries = $safe('Sales trend', fn () => $this->sales->timeSeries($filters, $salesMetric), ChartSeries::failed('Could not load the sales trend.'));
        $projectPerf = $safe('Project performance', fn () => $this->sales->projectPerformance($filters), []);
        $recent = $safe('Recent bookings', fn () => $this->sales->recentBookings($filters), []);

        $topSalespeople = $user->can('leads.view_all')
            ? $safe('Top salespeople', fn () => $this->sales->topSalespeople($filters), [])
            : null;

        // --- KPIs --------------------------------------------------------
        $err = fn (string $section) => $errors[$section] ?? null;
        $kpis = [
            new Kpi('total_projects', 'Total projects', $totalProjects, 'number', error: $err('Projects')),
            new Kpi('total_plots', 'Total plots', $inventoryDist === null ? null : array_sum($inventoryDist), 'number', error: $err('Inventory')),
            new Kpi('available_plots', 'Available plots', $inventoryDist[PlotStatus::Available->value] ?? null, 'number', error: $err('Inventory')),
            new Kpi('booked_plots', 'Booked plots', $inventoryDist[PlotStatus::Booked->value] ?? null, 'number', error: $err('Inventory')),

            new Kpi('total_bookings', 'Bookings', $salesNow['bookings'] ?? null, 'number', previous: $salesPrev['bookings'] ?? null, error: $err('Sales'), hint: 'Confirmed in period'),
            new Kpi('booking_value', 'Booking value', $salesNow['value'] ?? null, 'currency', previous: $salesPrev['value'] ?? null, error: $err('Sales')),
            new Kpi('total_collected', 'Collected', $collectionNow['collectedInPeriod'] ?? null, 'currency', previous: $collectionPrev['collectedInPeriod'] ?? null, error: $err('Collections'), hint: 'Successful payments in period'),
            new Kpi('outstanding', 'Outstanding', $collectionNow['outstanding'] ?? null, 'currency', error: $err('Collections'), hint: 'M8 installment walk'),
            new Kpi('overdue', 'Overdue', $collectionNow['overdue'] ?? null, 'currency', error: $err('Collections')),
            new Kpi('collection_percent', 'Collection %', $collectionNow['collectionPercent'] ?? null, 'percent', error: $err('Collections')),

            new Kpi('total_leads', 'Leads', $leadsNow['total'] ?? null, 'number', previous: $leadsPrev['total'] ?? null, error: $err('Leads')),
            new Kpi('converted_leads', 'Converted leads', $leadsNow['converted'] ?? null, 'number', previous: $leadsPrev['converted'] ?? null, error: $err('Leads')),
            new Kpi('conversion_percent', 'Conversion %', $leadsNow['conversionPercent'] ?? null, 'percent', previous: $leadsPrev['conversionPercent'] ?? null, error: $err('Leads')),

            new Kpi('registry_pending', 'Registry pending', $registryPending, 'number', error: $err('Registry')),
            new Kpi('possession_pending', 'Possession pending', $possessionPending, 'number', error: $err('Possession')),
            new Kpi('transfer_pending', 'Transfer pending', $transferPending, 'number', error: $err('Transfers')),
        ];

        // --- Attention required ----------------------------------------
        $attention = [
            $this->alert($user, 'overdue', 'Overdue customers', $overdueBookings, 'danger', 'collections.queue', 'collections.view', $err('Overdue customers')),
            $this->alert($user, 'registry', 'Registry cases pending', $registryPending, 'warning', 'registry.dashboard', 'registry.view', $err('Registry')),
            $this->alert($user, 'possession', 'Possession cases pending', $possessionPending, 'warning', 'possession.dashboard', 'possession.view', $err('Possession')),
            $this->alert($user, 'documents', 'Documents awaiting verification', $docsPending, 'info', 'documents.dashboard', 'documents.view', $err('Documents')),
            $this->alert($user, 'transfers', 'Transfers awaiting approval', $transferApproval, 'warning', 'transfers.dashboard', 'transfer.view', $err('Transfers')),
        ];

        return new ExecutiveDashboardData(
            kpis: $kpis,
            salesSeries: $salesSeries,
            salesMetric: $salesMetric,
            collectionOverview: $this->collectionOverview($collectionNow),
            inventoryDistribution: $inventoryDist ?? [],
            bookingStatusDistribution: $bookingStatus,
            projectPerformance: $projectPerf,
            topSalespeople: $topSalespeople,
            recentBookings: $recent,
            attention: $attention,
            filters: $filters,
            previousPeriod: $previous,
            generatedAt: now()->toIso8601String(),
            errors: array_values(array_unique($errors)),
        );
    }

    /**
     * @param  array<string, float|null>|null  $collection
     * @return array<string, float|null>
     */
    private function collectionOverview(?array $collection): array
    {
        if ($collection === null) {
            return [];
        }

        return [
            'Expected (period)' => $collection['expectedInPeriod'],
            'Collected (period)' => $collection['collectedInPeriod'],
            'Collected (all time)' => $collection['collectedAllTime'],
            'Outstanding' => $collection['outstanding'],
            'Overdue' => $collection['overdue'],
        ];
    }

    /**
     * @return array{key:string, label:string, count:int|null, url:string|null, tone:string, error:string|null}
     */
    private function alert(User $user, string $key, string $label, ?int $count, string $tone, string $route, string $permission, ?string $error): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'url' => $user->can($permission) ? route($route) : null,
            'tone' => $tone,
            'error' => $error,
        ];
    }
}
