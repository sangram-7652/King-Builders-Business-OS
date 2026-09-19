<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\BookingStatus;
use App\Queries\Reports\ReportFilterScope;
use App\Support\Reports\Concerns\MemoizesFilteredQueries;
use App\Support\Reports\Granularity;
use App\Support\Reports\ReportFilterData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The consolidated Management MIS aggregates (M11.5).
 *
 * This is **orchestration, not a new engine**: every financial figure comes
 * from {@see PaymentsAnalytics} (M7 truth — booking value vs successful
 * payments; there is no installment schedule left to raise "demand" against),
 * inventory from {@see InventoryAnalytics}, sales from {@see SalesAnalytics},
 * pending work from {@see OperationsAnalytics}. The only SQL written here is
 * the day/month bucketing of bookings, which no existing service exposes.
 *
 * Consistent period semantics across the daily + monthly tables:
 *   collected(P)   = Σ SUCCESS payments in P (by payment_date)
 */
class MisAnalytics
{
    use MemoizesFilteredQueries;

    /** Hard cap on daily rows so an unbounded custom window can't blow up. */
    public const MAX_DAILY_ROWS = 366;

    public function __construct(
        private readonly SalesAnalytics $sales,
        private readonly InventoryAnalytics $inventory,
        private readonly PaymentsAnalytics $payments,
        private readonly OperationsAnalytics $operations,
    ) {}

    /**
     * The management KPI block. Pure delegation — each figure is defined once,
     * in its own analytics service.
     *
     * @return array<string, int|float|null>
     */
    public function kpis(ReportFilterData $filters): array
    {
        $inv = $this->inventory->inventoryKpis($filters);
        $dist = $this->inventory->statusDistribution($filters);
        $sales = $this->sales->salesKpis($filters);
        $fin = $this->payments->kpis($filters);

        return [
            'total_projects' => $this->inventory->totalProjects($filters),
            'total_plots' => array_sum($dist),
            'available' => $inv['available'],
            'booked' => $inv['booked'],
            'registered' => $inv['registered'],
            'possession_completed' => $inv['possession'],

            'total_bookings' => $sales['bookings'],
            'booking_value' => $sales['value'],   // confirmed value in the period (matches total_bookings)
            'collected' => $fin['collected'],
            'outstanding' => $fin['outstanding'],

            'registry_pending' => $this->operations->registryPending($filters),
            'possession_pending' => $this->operations->possessionPending($filters),
            'transfer_pending' => $this->operations->transferPending($filters),
            'documents_pending' => $this->operations->documentsAwaitingVerification($filters),
        ];
    }

    /**
     * One row per calendar day in the window (capped, most-recent first when
     * capped). Every column is a SQL aggregate.
     *
     * @return array{rows: list<array<string, int|float|string>>, truncated: bool}
     */
    public function daily(ReportFilterData $filters): array
    {
        $bookings = $this->bookingsByDay($filters);
        $collected = $this->payments->collectedSeries($filters);

        $start = $filters->from->startOfDay();
        $end = $filters->to->startOfDay();
        $totalDays = (int) $start->diffInDays($end) + 1;
        $truncated = $totalDays > self::MAX_DAILY_ROWS;
        if ($truncated) {
            $start = $end->subDays(self::MAX_DAILY_ROWS - 1);
        }

        $rows = [];
        for ($cursor = $start; $cursor->lessThanOrEqualTo($end); $cursor = $cursor->addDay()) {
            $key = $cursor->toDateString();

            $rows[] = [
                'date' => $key,
                'bookings' => (int) ($bookings[$key]->c ?? 0),
                'booking_value' => (float) ($bookings[$key]->v ?? 0),
                'collected' => (float) ($collected[$key] ?? 0),
            ];
        }

        return ['rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * One row per calendar month the window touches.
     *
     * @return list<array<string, int|float|string|null>>
     */
    public function monthly(ReportFilterData $filters): array
    {
        $bookingsByDay = $this->bookingsByDay($filters);
        $bookingCount = $this->foldMonths($bookingsByDay, fn ($r) => (int) $r->c);
        $bookingValue = $this->foldMonths($bookingsByDay, fn ($r) => (float) $r->v);

        $collected = $this->payments->collectedSeries($filters);
        $collectedByMonth = [];
        foreach ($collected as $day => $v) {
            $collectedByMonth[substr($day, 0, 7)] = ($collectedByMonth[substr($day, 0, 7)] ?? 0.0) + $v;
        }

        $out = [];
        foreach (Granularity::months($filters) as [$key, $label]) {
            $out[] = [
                'month' => $label,
                'bookings' => $bookingCount[$key] ?? 0,
                'booking_value' => round($bookingValue[$key] ?? 0.0, 2),
                'collected' => round($collectedByMonth[$key] ?? 0.0, 2),
            ];
        }

        return $out;
    }

    /**
     * Project MIS: inventory split + sales value + M7 payments truth, merged
     * by project id.
     *
     * @return list<array<string, int|float|string|null>>
     */
    public function projects(ReportFilterData $filters): array
    {
        $inventory = collect($this->inventory->projectInventory($filters))->keyBy('project_id');
        $sales = collect($this->sales->projectSales($filters))->keyBy('project_id');
        $payments = collect($this->payments->projectCollection($filters))->keyBy('project_id');

        $ids = $inventory->keys()
            ->merge($sales->keys())->merge($payments->keys())
            ->unique()->values();

        return $ids->map(function ($id) use ($inventory, $sales, $payments) {
            $inv = $inventory->get($id);
            $sal = $sales->get($id);
            $pay = $payments->get($id);

            return [
                'project' => $inv['project'] ?? $sal['project'] ?? $pay['project'] ?? 'Unknown',
                'project_id' => (int) $id,
                'plots' => (int) ($inv['total'] ?? 0),
                'booked' => (int) ($inv['booked'] ?? 0),
                'available' => (int) ($inv['available'] ?? 0),
                'sales_value' => (float) ($sal['value'] ?? 0),
                'collected' => (float) ($pay['collected'] ?? 0),
                'outstanding' => (float) ($pay['outstanding'] ?? 0),
            ];
        })
            ->sortByDesc('outstanding')
            ->values()
            ->all();
    }

    /**
     * Salesperson MIS: sales performance + M7 payments truth, merged by user
     * id. `$onlyId` restricts the result to one user.
     *
     * @return list<array<string, int|float|string|null>>
     */
    public function salespeople(ReportFilterData $filters, ?int $onlyId = null): array
    {
        $perf = collect($this->sales->salespersonPerformance($filters, $onlyId))->keyBy('user_id');
        $payments = collect($this->payments->salespersonCollection($filters, $onlyId))->keyBy('user_id');

        $ids = $perf->keys()->merge($payments->keys())->unique()->values();

        return $ids->map(function ($id) use ($perf, $payments) {
            $p = $perf->get($id);
            $c = $payments->get($id);

            return [
                'name' => $p['name'] ?? $c['name'] ?? 'Unknown',
                'bookings' => (int) ($p['bookings'] ?? 0),
                'booking_value' => (float) ($p['value'] ?? 0),
                'collected' => (float) ($c['collected'] ?? 0),
                'outstanding' => (float) ($c['outstanding'] ?? 0),
            ];
        })
            ->sortByDesc('booking_value')
            ->values()
            ->all();
    }

    // -- day bucketing (the only SQL unique to MIS) ----------------------

    /**
     * @return Collection<string, object{c:int, v:float}>
     */
    private function bookingsByDay(ReportFilterData $filters)
    {
        return $this->remember('mis.bookingsByDay', $filters, function () use ($filters) {
            $query = DB::table('bookings')->whereNull('bookings.deleted_at')->where('bookings.status', BookingStatus::Confirmed->value);
            ReportFilterScope::dateRange($query, $filters, 'bookings.booking_date');
            ReportFilterScope::project($query, $filters, 'bookings.project_id');
            ReportFilterScope::block($query, $filters, 'bookings.block_id');
            ReportFilterScope::salesperson($query, $filters, 'bookings.created_by');

            return $query
                ->groupByRaw('date(bookings.booking_date)')
                ->selectRaw('date(bookings.booking_date) as d, count(*) as c, coalesce(sum(bookings.final_amount), 0) as v')
                ->get()
                ->keyBy('d');
        });
    }

    /**
     * Fold a day-keyed collection into `['YYYY-MM' => number]`.
     *
     * @param  Collection<string, object>  $daily
     * @param  callable(object): (int|float)  $value
     * @return array<string, int|float>
     */
    private function foldMonths($daily, callable $value): array
    {
        $out = [];
        foreach ($daily as $day => $row) {
            $month = CarbonImmutable::parse((string) $day)->format('Y-m');
            $out[$month] = ($out[$month] ?? 0) + $value($row);
        }

        return $out;
    }
}
