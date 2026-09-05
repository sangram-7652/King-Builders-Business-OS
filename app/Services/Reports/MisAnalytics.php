<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\BookingStatus;
use App\Enums\LeadStatus;
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
 * from {@see CollectionAnalytics} (M7/M8 truth — receivable is demand raised on
 * the live plan, outstanding is the installment walk, never "booking value −
 * collected"), inventory from {@see InventoryAnalytics}, sales from
 * {@see SalesAnalytics}, leads from {@see LeadAnalytics}, pending work from
 * {@see OperationsAnalytics}. The only SQL written here is the day/month
 * bucketing of leads and bookings, which no existing service exposes.
 *
 * Consistent period semantics across the daily + monthly tables:
 *   receivable(P)  = Σ installment amount due in P
 *   collected(P)   = Σ SUCCESS payments in P (by payment_date)
 *   outstanding(P) = Σ still-unpaid portion of installments due in P (as of now)
 *   overdue(P)     = same, restricted to installments now past due
 *   efficiency(P)  = collected / receivable × 100   (null when receivable = 0)
 */
class MisAnalytics
{
    use MemoizesFilteredQueries;

    /** Hard cap on daily rows so an unbounded custom window can't blow up. */
    public const MAX_DAILY_ROWS = 366;

    public function __construct(
        private readonly SalesAnalytics $sales,
        private readonly InventoryAnalytics $inventory,
        private readonly CollectionAnalytics $collection,
        private readonly LeadAnalytics $leads,
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
        $fin = $this->collection->kpis($filters);
        $leads = $this->leads->summary($filters);

        return [
            'total_projects' => $this->inventory->totalProjects($filters),
            'total_plots' => array_sum($dist),
            'available' => $inv['available'],
            'booked' => $inv['booked'],
            'registered' => $inv['registered'],
            'possession_completed' => $inv['possession'],

            'total_bookings' => $sales['bookings'],
            'booking_value' => $sales['value'],   // confirmed value in the period (matches total_bookings)
            'receivable' => $fin['receivable'],
            'collected' => $fin['collected'],
            'outstanding' => $fin['outstanding'],
            'overdue' => $fin['overdue'],
            'collection_efficiency' => $fin['efficiency'],

            'total_leads' => $leads['total'],
            'converted_leads' => $leads['converted'],
            'conversion_pct' => $leads['conversionPercent'],

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
        $leads = $this->leadsByDay($filters);
        $bookings = $this->bookingsByDay($filters);
        $demand = $this->collection->demandSeries($filters);
        $collected = $this->collection->collectedSeries($filters);

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
            $d = $demand[$key] ?? ['receivable' => 0.0, 'outstanding' => 0.0, 'overdue' => 0.0];

            $rows[] = [
                'date' => $key,
                'leads' => (int) ($leads[$key]->c ?? 0),
                'bookings' => (int) ($bookings[$key]->c ?? 0),
                'booking_value' => (float) ($bookings[$key]->v ?? 0),
                'receivable' => $d['receivable'],
                'collected' => (float) ($collected[$key] ?? 0),
                'outstanding' => $d['outstanding'],
                'overdue' => $d['overdue'],
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
        $leadsByDay = $this->leadsByDay($filters);
        $bookingsByDay = $this->bookingsByDay($filters);
        $leads = $this->foldMonths($leadsByDay, fn ($r) => (int) $r->c);
        $converted = $this->foldMonths($leadsByDay, fn ($r) => (int) $r->converted);
        $bookingCount = $this->foldMonths($bookingsByDay, fn ($r) => (int) $r->c);
        $bookingValue = $this->foldMonths($bookingsByDay, fn ($r) => (float) $r->v);

        $demand = $this->collection->demandSeries($filters);
        $collected = $this->collection->collectedSeries($filters);

        $demandByMonth = [];
        foreach ($demand as $day => $d) {
            $m = substr($day, 0, 7);
            $demandByMonth[$m]['receivable'] = ($demandByMonth[$m]['receivable'] ?? 0.0) + $d['receivable'];
            $demandByMonth[$m]['outstanding'] = ($demandByMonth[$m]['outstanding'] ?? 0.0) + $d['outstanding'];
            $demandByMonth[$m]['overdue'] = ($demandByMonth[$m]['overdue'] ?? 0.0) + $d['overdue'];
        }
        $collectedByMonth = [];
        foreach ($collected as $day => $v) {
            $collectedByMonth[substr($day, 0, 7)] = ($collectedByMonth[substr($day, 0, 7)] ?? 0.0) + $v;
        }

        $out = [];
        foreach (Granularity::months($filters) as [$key, $label]) {
            $receivable = round($demandByMonth[$key]['receivable'] ?? 0.0, 2);
            $collectedAmt = round($collectedByMonth[$key] ?? 0.0, 2);

            $out[] = [
                'month' => $label,
                'bookings' => $bookingCount[$key] ?? 0,
                'booking_value' => round($bookingValue[$key] ?? 0.0, 2),
                'receivable' => $receivable,
                'collected' => $collectedAmt,
                'outstanding' => round($demandByMonth[$key]['outstanding'] ?? 0.0, 2),
                'overdue' => round($demandByMonth[$key]['overdue'] ?? 0.0, 2),
                'collection_efficiency' => $receivable > 0.0 ? round($collectedAmt / $receivable * 100, 1) : null,
                'leads' => $leads[$key] ?? 0,
                'converted_leads' => $converted[$key] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * Project MIS: inventory split + sales value + M8 collection truth,
     * merged by project id.
     *
     * @return list<array<string, int|float|string|null>>
     */
    public function projects(ReportFilterData $filters): array
    {
        $inventory = collect($this->inventory->projectInventory($filters))->keyBy('project_id');
        $sales = collect($this->sales->projectSales($filters))->keyBy('project_id');
        $collection = collect($this->collection->projectCollection($filters))->keyBy('project_id');

        $ids = $inventory->keys()
            ->merge($sales->keys())->merge($collection->keys())
            ->unique()->values();

        return $ids->map(function ($id) use ($inventory, $sales, $collection) {
            $inv = $inventory->get($id);
            $sal = $sales->get($id);
            $col = $collection->get($id);

            return [
                'project' => $inv['project'] ?? $sal['project'] ?? $col['project'] ?? 'Unknown',
                'project_id' => (int) $id,
                'plots' => (int) ($inv['total'] ?? 0),
                'booked' => (int) ($inv['booked'] ?? 0),
                'available' => (int) ($inv['available'] ?? 0),
                'sales_value' => (float) ($sal['value'] ?? 0),
                'collected' => (float) ($col['collected'] ?? 0),
                'outstanding' => (float) ($col['outstanding'] ?? 0),
                'overdue' => (float) ($col['overdue'] ?? 0),
            ];
        })
            ->sortByDesc('outstanding')
            ->values()
            ->all();
    }

    /**
     * Salesperson MIS: sales performance + M8 collection truth, merged by user
     * id. `$onlyId` restricts to one user (a viewer without `leads.view_all`).
     *
     * @return list<array<string, int|float|string|null>>
     */
    public function salespeople(ReportFilterData $filters, ?int $onlyId = null): array
    {
        $perf = collect($this->sales->salespersonPerformance($filters, $onlyId))->keyBy('user_id');
        $collection = collect($this->collection->salespersonCollection($filters, $onlyId))->keyBy('user_id');

        $ids = $perf->keys()->merge($collection->keys())->unique()->values();

        return $ids->map(function ($id) use ($perf, $collection) {
            $p = $perf->get($id);
            $c = $collection->get($id);

            return [
                'name' => $p['name'] ?? $c['name'] ?? 'Unknown',
                'leads' => (int) ($p['leads'] ?? 0),
                'bookings' => (int) ($p['bookings'] ?? 0),
                'booking_value' => (float) ($p['value'] ?? 0),
                'collected' => (float) ($c['collected'] ?? 0),
                'outstanding' => (float) ($c['outstanding'] ?? 0),
                'conversion' => $p['conversion'] ?? null,
            ];
        })
            ->sortByDesc('booking_value')
            ->values()
            ->all();
    }

    /**
     * Collection MIS summary — straight from {@see CollectionAnalytics::kpis()}
     * (M8 truth), plus cash reconciliation.
     *
     * @return array<string, int|float|null>
     */
    public function collectionSummary(ReportFilterData $filters): array
    {
        $k = $this->collection->kpis($filters);
        $recon = $this->collection->reconciliation($filters);

        return [
            'booking_value' => $recon['bookingValue'],
            'receivable' => $k['receivable'],
            'collected' => $k['collected'],
            'cash_collected' => $recon['cashCollected'],
            'outstanding' => $k['outstanding'],
            'overdue' => $k['overdue'],
            'collection_efficiency' => $k['efficiency'],
        ];
    }

    // -- day bucketing (the only SQL unique to MIS) ----------------------

    /**
     * @return Collection<string, object{c:int, converted:int}>
     */
    private function leadsByDay(ReportFilterData $filters)
    {
        return $this->remember('mis.leadsByDay', $filters, function () use ($filters) {
            $query = DB::table('leads')->whereNull('deleted_at');
            ReportFilterScope::dateRange($query, $filters, 'leads.created_at');
            ReportFilterScope::salesperson($query, $filters, 'leads.assigned_to');
            ReportFilterScope::leadSource($query, $filters, 'leads.lead_source_id');

            return $query
                ->groupByRaw('date(leads.created_at)')
                ->selectRaw('date(leads.created_at) as d, count(*) as c')
                ->selectRaw('sum(case when leads.status = ? then 1 else 0 end) as converted', [LeadStatus::Converted->value])
                ->get()
                ->keyBy('d');
        });
    }

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
