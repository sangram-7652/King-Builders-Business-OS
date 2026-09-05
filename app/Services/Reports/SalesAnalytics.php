<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PlotStatus;
use App\Models\Booking;
use App\Models\User;
use App\Queries\Reports\ReportFilterScope;
use App\Support\Reports\ChartSeries;
use App\Support\Reports\ReportFilterData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SQL-aggregated sales analytics for the executive dashboard (M11.2) and the
 * sales report (M11.3).
 *
 * Every figure is `COUNT` / `SUM` / `AVG` / conditional aggregation on the
 * domain tables — no dataset is hydrated to be totalled in PHP. "Value" means
 * `SUM(final_amount)` (the M6 pricing result — no sales calculation is
 * re-implemented here). All queries route their filtering through
 * {@see ReportFilterScope}.
 *
 * The reported booking status is the `booking_status` filter when set, else
 * CONFIRMED — so a user can inspect e.g. cancelled bookings without the
 * numbers silently including them by default.
 */
class SalesAnalytics
{
    private const CONFIRMED = BookingStatus::Confirmed;

    private function effectiveStatus(ReportFilterData $filters): BookingStatus
    {
        return $filters->bookingStatus ?? self::CONFIRMED;
    }

    /**
     * @return array{bookings: int, value: float, cancelled: int, all_statuses: int}
     */
    public function bookingSummary(ReportFilterData $filters): array
    {
        $row = $this->scopedBookings($filters)
            ->selectRaw('count(*) as all_statuses')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as bookings', [self::CONFIRMED->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as cancelled', [BookingStatus::Cancelled->value])
            ->selectRaw('coalesce(sum(case when status = ? then final_amount else 0 end), 0) as value', [self::CONFIRMED->value])
            ->first();

        return [
            'bookings' => (int) ($row->bookings ?? 0),
            'value' => (float) ($row->value ?? 0),
            'cancelled' => (int) ($row->cancelled ?? 0),
            'all_statuses' => (int) ($row->all_statuses ?? 0),
        ];
    }

    /**
     * Count per booking status for the current filter window (all statuses,
     * zero-filled). Uses the M6 status enum — no invented states.
     *
     * @return array<string, int>
     */
    public function statusDistribution(ReportFilterData $filters): array
    {
        $counts = $this->scopedBookings($filters)
            ->groupBy('status')
            ->selectRaw('status, count(*) as c')
            ->pluck('c', 'status');

        $out = [];
        foreach (BookingStatus::cases() as $status) {
            $out[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $out;
    }

    /**
     * Confirmed booking activity over the window, aggregated in SQL to one row
     * per day, then rolled up in PHP to the display granularity (day / week /
     * month) so it stays portable and never loads raw bookings.
     */
    public function timeSeries(ReportFilterData $filters, string $metric = 'value'): ChartSeries
    {
        $granularity = $this->granularityFor($filters);

        $daily = $this->scopedBookings($filters)
            ->where('status', $this->effectiveStatus($filters)->value)
            ->selectRaw('date(booking_date) as d')
            ->selectRaw('count(*) as bookings')
            ->selectRaw('coalesce(sum(final_amount), 0) as value')
            ->groupBy('d')
            ->get();

        // Fold the (≤ 366) daily aggregate rows into buckets — O(days + buckets).
        $acc = [];
        foreach ($daily as $row) {
            $key = $this->bucketKey(CarbonImmutable::parse((string) $row->d), $granularity);
            $acc[$key]['bookings'] = ($acc[$key]['bookings'] ?? 0) + (int) $row->bookings;
            $acc[$key]['value'] = ($acc[$key]['value'] ?? 0.0) + (float) $row->value;
        }

        $points = [];
        foreach ($this->buckets($filters, $granularity) as [$key, $label]) {
            $points[] = [
                'label' => $label,
                'key' => $key,
                'values' => [
                    'bookings' => (float) ($acc[$key]['bookings'] ?? 0),
                    'value' => (float) ($acc[$key]['value'] ?? 0.0),
                ],
            ];
        }

        return new ChartSeries(points: $points, granularity: $granularity, metrics: ['bookings', 'value']);
    }

    /**
     * One row per project: current inventory + period bookings/value + current
     * collected/outstanding. Five bounded aggregate queries merged in PHP — no
     * per-project query loop.
     *
     * @return list<array{project_id:int, project:string, plots:int, booked:int, value:float, collected:float, outstanding:float}>
     */
    public function projectPerformance(ReportFilterData $filters): array
    {
        $projects = ReportFilterScope::project(
            DB::table('projects')->where('is_active', true), $filters, 'id'
        )->orderBy('name')->pluck('name', 'id');

        if ($projects->isEmpty()) {
            return [];
        }
        $ids = $projects->keys()->all();

        $periodBookings = ReportFilterScope::salesperson(
            ReportFilterScope::dateRange(
                DB::table('bookings')->whereNull('deleted_at')
                    ->where('status', self::CONFIRMED->value)->whereIn('project_id', $ids),
                $filters,
                'booking_date',
            ),
            $filters,
            'created_by',
        )->groupBy('project_id')
            ->selectRaw('project_id, count(*) as booked, coalesce(sum(final_amount), 0) as value')
            ->get()->keyBy('project_id');

        $allTimeValue = DB::table('bookings')
            ->whereNull('deleted_at')
            ->where('status', self::CONFIRMED->value)->whereIn('project_id', $ids)
            ->groupBy('project_id')
            ->selectRaw('project_id, coalesce(sum(final_amount), 0) as v')
            ->pluck('v', 'project_id');

        $collected = DB::table('payments')
            ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->whereNull('payments.deleted_at')
            ->whereNull('bookings.deleted_at')
            ->where('payments.status', PaymentStatus::Success->value)
            ->where('bookings.status', self::CONFIRMED->value)
            ->whereIn('bookings.project_id', $ids)
            ->groupBy('bookings.project_id')
            ->selectRaw('bookings.project_id as pid, coalesce(sum(payments.amount), 0) as c')
            ->pluck('c', 'pid');

        $plots = DB::table('plots')
            ->whereNull('deleted_at')
            ->where('is_active', true)->whereIn('project_id', $ids)
            ->groupBy('project_id')
            ->selectRaw('project_id, count(*) as c')
            ->pluck('c', 'project_id');

        $rows = [];
        foreach ($projects as $id => $name) {
            $value = (float) ($periodBookings[$id]->value ?? 0);
            $collectedAmt = (float) ($collected[$id] ?? 0);
            $rows[] = [
                'project_id' => (int) $id,
                'project' => (string) $name,
                'plots' => (int) ($plots[$id] ?? 0),
                'booked' => (int) ($periodBookings[$id]->booked ?? 0),
                'value' => $value,
                'collected' => $collectedAmt,
                'outstanding' => (float) ($allTimeValue[$id] ?? 0) - $collectedAmt,
            ];
        }

        usort($rows, fn ($a, $b) => $b['value'] <=> $a['value']);

        return $rows;
    }

    /**
     * Top salespeople by confirmed booking value in the window. Two queries.
     *
     * @return list<array{user_id:int, name:string, bookings:int, value:float}>
     */
    public function topSalespeople(ReportFilterData $filters, int $limit = 5): array
    {
        $rows = $this->scopedBookings($filters)
            ->where('status', self::CONFIRMED->value)
            ->whereNotNull('created_by')
            ->groupBy('created_by')
            ->selectRaw('created_by, count(*) as bookings, coalesce(sum(final_amount), 0) as value')
            ->orderByDesc('value')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $names = User::query()->whereIn('id', $rows->pluck('created_by'))->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'user_id' => (int) $r->created_by,
            'name' => (string) ($names[$r->created_by] ?? 'Unknown'),
            'bookings' => (int) $r->bookings,
            'value' => (float) $r->value,
        ])->all();
    }

    /**
     * Latest non-draft bookings in the window, buyer / plot / project
     * eager-loaded (no N+1).
     *
     * @return list<array{id:int, booking_number:string, customer:string, plot:string, project:string, amount:float, date:string, status:string}>
     */
    public function recentBookings(ReportFilterData $filters, int $limit = 8): array
    {
        return $this->scopedBookings($filters)
            ->where('status', '!=', BookingStatus::Draft->value)
            ->with([
                'project:id,name',
                'plot:id,plot_number',
                'primaryBookingBuyer.buyer:id,first_name,middle_name,last_name',
            ])
            ->orderByDesc('booking_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Booking $b) => [
                'id' => $b->id,
                'booking_number' => $b->booking_number,
                'customer' => $b->primaryBookingBuyer?->buyer?->fullName() ?? '—',
                'plot' => $b->plot?->plot_number ?? '—',
                'project' => $b->project?->name ?? '—',
                'amount' => (float) $b->final_amount,
                'date' => $b->booking_date->toDateString(),
                'status' => $b->status->value,
            ])
            ->all();
    }

    // --- M11.3 sales report -----------------------------------------

    /**
     * Headline sales figures for the window: booking count, total value and the
     * average booking value (null when there are no bookings).
     *
     * @return array{bookings: int, value: float, average: float|null}
     */
    public function salesKpis(ReportFilterData $filters): array
    {
        $row = $this->scopedBookings($filters)
            ->where('status', $this->effectiveStatus($filters)->value)
            ->selectRaw('count(*) as bookings, coalesce(sum(final_amount), 0) as value, avg(final_amount) as average')
            ->first();

        $bookings = (int) ($row->bookings ?? 0);

        return [
            'bookings' => $bookings,
            'value' => (float) ($row->value ?? 0),
            'average' => $bookings > 0 ? round((float) $row->average, 2) : null,
        ];
    }

    /**
     * Bookings per day / per week over the selected window.
     *
     * @return array{bookings: int, days: int, per_day: float, per_week: float}
     */
    public function bookingVelocity(ReportFilterData $filters): array
    {
        $bookings = (int) $this->scopedBookings($filters)
            ->where('status', $this->effectiveStatus($filters)->value)
            ->count();

        $days = (int) max(1, $filters->from->startOfDay()->diffInDays($filters->to->startOfDay()) + 1);

        return [
            'bookings' => $bookings,
            'days' => $days,
            'per_day' => round($bookings / $days, 2),
            'per_week' => round($bookings / $days * 7, 2),
        ];
    }

    /**
     * Project-wise sales: bookings, value, average and the absorption ("sold")
     * rate. Three bounded aggregate queries merged in PHP.
     *
     * @return list<array{project_id:int, project:string, bookings:int, value:float, average:float|null, sold_pct:float|null}>
     */
    public function projectSales(ReportFilterData $filters): array
    {
        $projects = ReportFilterScope::project(
            DB::table('projects')->where('is_active', true), $filters, 'id',
        )->orderBy('name')->pluck('name', 'id');

        if ($projects->isEmpty()) {
            return [];
        }
        $ids = $projects->keys()->all();

        $bookings = $this->bookingsByColumn($filters, 'project_id', $ids);
        $plots = $this->plotAbsorptionByColumn($filters, 'project_id', $ids);

        $rows = [];
        foreach ($projects as $id => $name) {
            $count = (int) ($bookings[$id]->c ?? 0);
            $value = (float) ($bookings[$id]->v ?? 0);
            $total = (int) ($plots[$id]->total ?? 0);
            $available = (int) ($plots[$id]->available ?? 0);

            $rows[] = [
                'project_id' => (int) $id,
                'project' => (string) $name,
                'bookings' => $count,
                'value' => $value,
                'average' => $count > 0 ? round($value / $count, 2) : null,
                'sold_pct' => $total > 0 ? round(($total - $available) / $total * 100, 1) : null,
            ];
        }

        usort($rows, fn ($a, $b) => $b['value'] <=> $a['value']);

        return $rows;
    }

    /**
     * Block-wise sales: inventory split + booking value + absorption.
     *
     * @return list<array{block_id:int, project_id:int, block:string, project:string, total:int, available:int, booked:int, value:float, sold_pct:float|null}>
     */
    public function blockSales(ReportFilterData $filters): array
    {
        $blocks = ReportFilterScope::block(
            ReportFilterScope::project(
                DB::table('blocks')->where('blocks.is_active', true), $filters, 'blocks.project_id',
            ),
            $filters,
            'blocks.id',
        )->join('projects', 'projects.id', '=', 'blocks.project_id')
            ->orderBy('projects.name')->orderBy('blocks.name')
            ->get(['blocks.id as id', 'blocks.name as name', 'blocks.project_id as project_id', 'projects.name as project_name']);

        if ($blocks->isEmpty()) {
            return [];
        }
        $ids = $blocks->pluck('id')->all();

        $bookings = $this->bookingsByColumn($filters, 'block_id', $ids, scopeBlock: false);
        $plots = $this->plotAbsorptionByColumn($filters, 'block_id', $ids, scopeBlock: false);

        return $blocks->map(function ($b) use ($bookings, $plots) {
            $total = (int) ($plots[$b->id]->total ?? 0);
            $available = (int) ($plots[$b->id]->available ?? 0);

            return [
                'block_id' => (int) $b->id,
                'project_id' => (int) $b->project_id,
                'block' => (string) $b->name,
                'project' => (string) $b->project_name,
                'total' => $total,
                'available' => $available,
                'booked' => (int) ($plots[$b->id]->booked ?? 0),
                'value' => (float) ($bookings[$b->id]->v ?? 0),
                'sold_pct' => $total > 0 ? round(($total - $available) / $total * 100, 1) : null,
            ];
        })->all();
    }

    /**
     * Salesperson performance: leads (assigned in window), bookings, value and
     * conversion. `$onlyId` restricts the result to one user (a viewer without
     * `leads.view_all` only ever sees their own row).
     *
     * @return list<array{user_id:int, name:string, leads:int, bookings:int, value:float, conversion:float|null}>
     */
    public function salespersonPerformance(ReportFilterData $filters, ?int $onlyId = null, string $sort = 'value'): array
    {
        $bookingRows = ReportFilterScope::salesperson(
            $this->scopedBookings($filters)->where('status', $this->effectiveStatus($filters)->value),
            $filters,
            'created_by',
        )
            ->when($onlyId !== null, fn ($q) => $q->where('created_by', $onlyId))
            ->whereNotNull('created_by')
            ->groupBy('created_by')
            ->selectRaw('created_by as uid, count(*) as bookings, coalesce(sum(final_amount), 0) as value')
            ->get()->keyBy('uid');

        $leadQuery = ReportFilterScope::leadSource(
            ReportFilterScope::salesperson(
                ReportFilterScope::dateRange(DB::table('leads')->whereNull('deleted_at'), $filters, 'created_at'),
                $filters,
                'assigned_to',
            ),
            $filters,
        )
            ->when($onlyId !== null, fn ($q) => $q->where('assigned_to', $onlyId))
            ->whereNotNull('assigned_to')
            ->groupBy('assigned_to')
            ->selectRaw('assigned_to as uid, count(*) as leads')
            ->get()->keyBy('uid');

        $ids = $bookingRows->keys()->merge($leadQuery->keys())->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $names = User::query()->whereIn('id', $ids)->pluck('name', 'id');

        $rows = $ids->map(function ($uid) use ($bookingRows, $leadQuery, $names) {
            $bookings = (int) ($bookingRows[$uid]->bookings ?? 0);
            $leads = (int) ($leadQuery[$uid]->leads ?? 0);

            return [
                'user_id' => (int) $uid,
                'name' => (string) ($names[$uid] ?? 'Unknown'),
                'leads' => $leads,
                'bookings' => $bookings,
                'value' => (float) ($bookingRows[$uid]->value ?? 0),
                'conversion' => $leads > 0 ? round($bookings / $leads * 100, 1) : null,
            ];
        })->all();

        $key = in_array($sort, ['bookings', 'conversion', 'value'], true) ? $sort : 'value';
        usort($rows, fn ($a, $b) => ($b[$key] ?? -1) <=> ($a[$key] ?? -1));

        return $rows;
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, object>
     */
    private function bookingsByColumn(ReportFilterData $filters, string $column, array $ids, bool $scopeBlock = true): Collection
    {
        $query = ReportFilterScope::salesperson(
            ReportFilterScope::dateRange(
                DB::table('bookings')
                    ->whereNull('deleted_at')
                    ->where('status', $this->effectiveStatus($filters)->value)
                    ->whereIn($column, $ids),
                $filters,
                'booking_date',
            ),
            $filters,
            'created_by',
        );

        if ($scopeBlock) {
            ReportFilterScope::block($query, $filters, 'block_id');
        }

        return $query->groupBy($column)
            ->selectRaw("{$column} as k, count(*) as c, coalesce(sum(final_amount), 0) as v")
            ->get()->keyBy('k');
    }

    /**
     * Current plot inventory split (total / available / booked) grouped by a
     * plot column. Snapshot — no date filter.
     *
     * @param  list<int>  $ids
     * @return Collection<int, object>
     */
    private function plotAbsorptionByColumn(ReportFilterData $filters, string $column, array $ids, bool $scopeBlock = true): Collection
    {
        $query = DB::table('plots')->whereNull('deleted_at')->where('is_active', true)->whereIn($column, $ids);

        if ($scopeBlock) {
            ReportFilterScope::block($query, $filters, 'block_id');
        }

        return $query->groupBy($column)
            ->selectRaw("{$column} as k, count(*) as total")
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as available', [PlotStatus::Available->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as booked', [PlotStatus::Booked->value])
            ->get()->keyBy('k');
    }

    /**
     * @return Builder<Booking>
     */
    private function scopedBookings(ReportFilterData $filters)
    {
        $query = Booking::query();
        ReportFilterScope::dateRange($query, $filters, 'booking_date');
        ReportFilterScope::project($query, $filters, 'project_id');
        ReportFilterScope::block($query, $filters, 'block_id');
        ReportFilterScope::salesperson($query, $filters, 'created_by');

        return $query;
    }

    private function granularityFor(ReportFilterData $filters): string
    {
        $days = $filters->from->startOfDay()->diffInDays($filters->to->startOfDay()) + 1;

        return match (true) {
            $days <= 45 => 'day',
            $days <= 183 => 'week',
            default => 'month',
        };
    }

    private function bucketKey(CarbonImmutable $date, string $granularity): string
    {
        return match ($granularity) {
            'week' => $date->startOfWeek()->toDateString(),
            'month' => $date->format('Y-m'),
            default => $date->toDateString(),
        };
    }

    /**
     * The ordered, gap-filled list of buckets spanning the window.
     *
     * @return list<array{0: string, 1: string}> [key, label]
     */
    private function buckets(ReportFilterData $filters, string $granularity): array
    {
        $cursor = match ($granularity) {
            'week' => $filters->from->startOfWeek(),
            'month' => $filters->from->startOfMonth(),
            default => $filters->from->startOfDay(),
        };
        $end = $filters->to->startOfDay();
        $buckets = [];

        while ($cursor->lessThanOrEqualTo($end)) {
            [$next, $label] = match ($granularity) {
                'week' => [$cursor->addWeek(), 'w/c '.$cursor->format('d M')],
                'month' => [$cursor->addMonthNoOverflow(), $cursor->format('M Y')],
                default => [$cursor->addDay(), $cursor->format('d M')],
            };
            $buckets[] = [$this->bucketKey($cursor, $granularity), $label];
            $cursor = $next;
        }

        return $buckets;
    }
}
