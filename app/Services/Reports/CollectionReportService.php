<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\User;
use App\Support\Reports\ChartSeries;
use App\Support\Reports\CollectionFilters;
use App\Support\Reports\CollectionReportData;
use App\Support\Reports\Kpi;
use App\Support\Reports\PreviousPeriod;
use App\Support\Reports\ReportFilterData;
use Illuminate\Pagination\LengthAwarePaginator;
use Throwable;

/**
 * Assembles the collections report (M11.4).
 *
 * Pure orchestration over {@see CollectionAnalytics} — every figure is a
 * bounded SQL aggregate against M7/M8 tables (no dataset hydrated, no
 * per-customer loop). Each section is isolated: a failing query records an
 * error and yields `null` / an empty result, never a fake `0`.
 *
 * No caching: the page runs ~14 aggregate queries + one paginated list on
 * indexed columns. If that ever changes this method is the single wrap point;
 * a cache key MUST combine the user id with `md5` of
 * `$filters->toQueryString() + $extras->toQueryString()`.
 */
class CollectionReportService
{
    public function __construct(private readonly CollectionAnalytics $analytics) {}

    public function build(
        ReportFilterData $filters,
        CollectionFilters $extras,
        User $user,
        string $recoverySort = 'days_overdue',
    ): CollectionReportData {
        $recoverySort = in_array($recoverySort, ['days_overdue', 'outstanding'], true) ? $recoverySort : 'days_overdue';
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

        $now = $safe('Collection KPIs', fn () => $this->analytics->kpis($filters), null);

        $trend = $safe('Collection trend', fn () => $this->analytics->trend($filters), [
            'trend' => ChartSeries::failed('Could not load the collection trend.'),
            'efficiency' => ChartSeries::failed('Could not load the efficiency trend.'),
        ]);

        $recon = $safe('Booking vs collection', fn () => $this->analytics->reconciliation($filters), null);

        // Period-scoped cash for the "Cash collected" KPI + its like-for-like
        // previous-period delta (reconciliation cash is all-time, for the
        // booking-value comparison only).
        $cashNow = $safe('Booking vs collection', fn () => $this->analytics->cashCollectedInPeriod($filters), null);
        $cashPrev = $cashNow === null ? null : $safe('Booking vs collection', fn () => $this->analytics->cashCollectedInPeriod($previous), null);

        $ageing = $safe('Ageing', fn () => $this->analytics->ageing($filters), []);
        $projectCol = $safe('Project collection', fn () => $this->analytics->projectCollection($filters), []);
        $blockCol = $safe('Block collection', fn () => $this->analytics->blockCollection($filters), []);

        $scoped = ! $user->can('leads.view_all');
        $spCol = $safe('Salesperson collection', fn () => $this->analytics->salespersonCollection(
            $filters, $scoped ? $user->getKey() : null,
        ), []);

        $topCustomers = $safe('Top customers', fn () => $this->analytics->topCustomers($filters), ['outstanding' => [], 'overdue' => []]);
        $paymentMethods = $safe('Payment methods', fn () => $this->analytics->paymentMethods($filters, $extras->paymentModeId), []);
        $cheques = $safe('Cheque analytics', fn () => $this->analytics->cheques($filters), [
            'received' => 0, 'cleared' => 0, 'pending' => 0, 'bounced' => 0, 'bounced_amount' => 0.0, 'bank_charges' => 0.0,
        ]);
        $monthly = $safe('Monthly collection', fn () => $this->analytics->monthly($filters), []);
        $expected = $safe('Expected collection', fn () => $this->analytics->expected($filters), ['next7' => 0.0, 'next30' => 0.0]);

        $recovery = $safe(
            'Customer recovery',
            fn () => $this->analytics->recoveryReport($filters, $extras, $recoverySort, 25),
            new LengthAwarePaginator([], 0, 25),
        );
        $recovery->appends($filters->toQueryString() + $extras->toQueryString() + ['recovery_sort' => $recoverySort]);

        $err = fn (string $s) => $errors[$s] ?? null;

        $kpis = [
            new Kpi('receivable', 'Total receivable', $now['receivable'] ?? null, 'currency', error: $err('Collection KPIs'), hint: 'Demand raised (M7 plan)'),
            new Kpi('collected', 'Total collected', $now === null ? null : $now['receivable'] - $now['outstanding'], 'currency', error: $err('Collection KPIs'), hint: 'Settled against demand'),
            new Kpi('outstanding', 'Outstanding', $now['outstanding'] ?? null, 'currency', error: $err('Collection KPIs'), hint: 'M8 installment walk'),
            new Kpi('overdue', 'Overdue', $now['overdue'] ?? null, 'currency', error: $err('Collection KPIs')),
            new Kpi('efficiency', 'Collection efficiency', $now['efficiency'] ?? null, 'percent', error: $err('Collection KPIs')),
            new Kpi('cash_collected', 'Cash collected', $cashNow, 'currency',
                previous: $cashPrev, error: $err('Booking vs collection'), hint: 'SUCCESS payments in period'),
        ];

        return new CollectionReportData(
            kpis: $kpis,
            trend: $trend['trend'],
            efficiencyTrend: $trend['efficiency'],
            ageing: $ageing,
            projectCollection: $projectCol,
            blockCollection: $blockCol,
            salespersonCollection: $spCol,
            salespeopleScoped: $scoped,
            topCustomers: $topCustomers,
            paymentMethods: $paymentMethods,
            cheques: $cheques,
            monthly: $monthly,
            expected: $expected,
            reconciliation: $recon ?? ['bookingValue' => 0.0, 'receivable' => 0.0, 'cashCollected' => 0.0, 'outstanding' => 0.0, 'unallocated' => 0.0],
            recovery: $recovery,
            recoverySort: $recoverySort,
            filters: $filters,
            extras: $extras,
            errors: $errors,
        );
    }
}
