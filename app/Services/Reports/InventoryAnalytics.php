<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\AgingBucket;
use App\Enums\BookingStatus;
use App\Enums\PlotStatus;
use App\Enums\RegistryCaseStatus;
use App\Models\Plot;
use App\Models\Project;
use App\Queries\Reports\ReportFilterScope;
use App\Support\Reports\InventoryFilters;
use App\Support\Reports\ReportFilterData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SQL-aggregated inventory analytics for the executive dashboard (M11.2) and
 * the inventory report (M11.3).
 *
 * The state distribution is driven by {@see PlotStatus} — every current M4
 * state is listed, zero-filled; nothing is invented. Inventory is a snapshot,
 * so it respects the project / block / plot-status filters (and the M11.3
 * price / size ranges) but never the date window or the salesperson filter.
 *
 * "Registered" = a plot whose confirmed booking has a COMPLETED M9 registry
 * case. "Possession completed" = the M4 `possession_completed` plot state
 * added in M10. Ageing reuses the M8 {@see AgingBucket} boundaries (identical
 * to the brief's) against `plots.created_at` — the plot's time in inventory.
 */
class InventoryAnalytics
{
    /**
     * @return array<string, int> PlotStatus value => count (every case present)
     */
    public function statusDistribution(ReportFilterData $filters, ?InventoryFilters $extras = null): array
    {
        $counts = $this->scopedPlots($filters, $extras)
            ->groupBy('plots.status')
            ->selectRaw('plots.status as status, count(*) as c')
            ->pluck('c', 'status');

        $out = [];
        foreach (PlotStatus::cases() as $status) {
            $out[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $out;
    }

    /**
     * @return array{total:int, available:int, booked:int, registered:int, possession:int}
     */
    public function inventoryKpis(ReportFilterData $filters, ?InventoryFilters $extras = null): array
    {
        $row = $this->scopedPlots($filters, $extras)
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when plots.status = ? then 1 else 0 end) as available', [PlotStatus::Available->value])
            ->selectRaw('sum(case when plots.status = ? then 1 else 0 end) as booked', [PlotStatus::Booked->value])
            ->selectRaw('sum(case when plots.status = ? then 1 else 0 end) as possession', [PlotStatus::PossessionCompleted->value])
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'available' => (int) ($row->available ?? 0),
            'booked' => (int) ($row->booked ?? 0),
            'registered' => $this->registeredCount($filters, $extras),
            'possession' => (int) ($row->possession ?? 0),
        ];
    }

    public function totalProjects(ReportFilterData $filters): int
    {
        $query = Project::query()->where('is_active', true);
        ReportFilterScope::project($query, $filters, 'id');

        return $query->count();
    }

    /**
     * Project inventory table.
     *
     * @return list<array{project_id:int, project:string, total:int, available:int, booked:int, registered:int, possession:int, sold_pct:float|null}>
     */
    public function projectInventory(ReportFilterData $filters, ?InventoryFilters $extras = null): array
    {
        $projects = ReportFilterScope::project(
            DB::table('projects')->where('is_active', true), $filters, 'id',
        )->orderBy('name')->pluck('name', 'id');

        return $this->rollup($projects, 'plots.project_id', $filters, $extras, fn ($id, $name) => [
            'project_id' => (int) $id,
            'project' => (string) $name,
        ]);
    }

    /**
     * Block inventory table.
     *
     * @return list<array{block_id:int, project_id:int, block:string, project:string, total:int, available:int, booked:int, registered:int, possession:int, sold_pct:float|null}>
     */
    public function blockInventory(ReportFilterData $filters, ?InventoryFilters $extras = null): array
    {
        $blocks = ReportFilterScope::block(
            ReportFilterScope::project(
                DB::table('blocks')->where('blocks.is_active', true), $filters, 'blocks.project_id',
            ),
            $filters,
            'blocks.id',
        )->join('projects', 'projects.id', '=', 'blocks.project_id')
            ->orderBy('projects.name')->orderBy('blocks.name')
            ->get(['blocks.id as id', 'blocks.name as name', 'blocks.project_id as project_id', 'projects.name as project_name'])
            ->keyBy('id');

        return $this->rollup($blocks->map(fn ($b) => $b->name), 'plots.block_id', $filters, $extras, fn ($id, $name) => [
            'block_id' => (int) $id,
            'project_id' => (int) ($blocks[$id]->project_id ?? 0),
            'block' => (string) $name,
            'project' => (string) ($blocks[$id]->project_name ?? '—'),
        ]);
    }

    /**
     * Available plots for the drill-down list. Paginated (never a full load).
     * Eager-loads block + size for the row cells (no N+1).
     *
     * @return LengthAwarePaginator<int, Plot>
     */
    public function availablePlots(ReportFilterData $filters, InventoryFilters $extras, int $perPage = 20): LengthAwarePaginator
    {
        $query = Plot::query()
            ->where('is_active', true)
            ->where('status', $filters->plotStatus?->value ?? PlotStatus::Available->value)
            ->with(['block:id,name', 'size:id,name']);

        ReportFilterScope::project($query, $filters, 'project_id');
        ReportFilterScope::block($query, $filters, 'block_id');
        $this->applySizeRange($query, $extras);

        return $query
            ->orderBy('created_at')          // oldest / stalest first
            ->orderBy('plot_number')
            ->paginate($perPage);
    }

    /**
     * Ageing of the currently-available plots by time since `plots.created_at`.
     * One query, portable (threshold-date comparisons, no DB date maths).
     *
     * @return array<string, int> AgingBucket value => count
     */
    public function ageing(ReportFilterData $filters, ?InventoryFilters $extras = null): array
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $d = fn (int $days) => $now->subDays($days)->toDateTimeString();

        $row = $this->scopedPlots($filters, $extras)
            ->where('plots.status', PlotStatus::Available->value)
            ->selectRaw('sum(case when plots.created_at >= ? then 1 else 0 end) as b0', [$d(30)])
            ->selectRaw('sum(case when plots.created_at < ? and plots.created_at >= ? then 1 else 0 end) as b1', [$d(30), $d(60)])
            ->selectRaw('sum(case when plots.created_at < ? and plots.created_at >= ? then 1 else 0 end) as b2', [$d(60), $d(90)])
            ->selectRaw('sum(case when plots.created_at < ? and plots.created_at >= ? then 1 else 0 end) as b3', [$d(90), $d(180)])
            ->selectRaw('sum(case when plots.created_at < ? then 1 else 0 end) as b4', [$d(180)])
            ->first();

        return [
            AgingBucket::Days0To30->value => (int) ($row->b0 ?? 0),
            AgingBucket::Days31To60->value => (int) ($row->b1 ?? 0),
            AgingBucket::Days61To90->value => (int) ($row->b2 ?? 0),
            AgingBucket::Days91To180->value => (int) ($row->b3 ?? 0),
            AgingBucket::Days180Plus->value => (int) ($row->b4 ?? 0),
        ];
    }

    /**
     * Price bands from CONFIRMED bookings' M6 `final_amount` (an available plot
     * has no stored price). One query, conditional aggregation.
     *
     * @return list<array{band:string, bookings:int, value:float, average:float|null}>
     */
    public function priceBands(ReportFilterData $filters): array
    {
        $bands = [
            ['band' => 'Under ₹25 L', 'min' => 0, 'max' => 25_00_000],
            ['band' => '₹25 L – ₹50 L', 'min' => 25_00_000, 'max' => 50_00_000],
            ['band' => '₹50 L – ₹1 Cr', 'min' => 50_00_000, 'max' => 1_00_00_000],
            ['band' => '₹1 Cr – ₹2 Cr', 'min' => 1_00_00_000, 'max' => 2_00_00_000],
            ['band' => '₹2 Cr+', 'min' => 2_00_00_000, 'max' => null],
        ];

        $query = DB::table('bookings')->whereNull('deleted_at')->where('status', BookingStatus::Confirmed->value);
        ReportFilterScope::dateRange($query, $filters, 'booking_date');
        ReportFilterScope::project($query, $filters, 'project_id');
        ReportFilterScope::block($query, $filters, 'block_id');
        ReportFilterScope::salesperson($query, $filters, 'created_by');

        foreach ($bands as $i => $b) {
            $lower = $b['max'] === null ? "final_amount >= {$b['min']}" : "final_amount >= {$b['min']} and final_amount < {$b['max']}";
            $query->selectRaw("sum(case when {$lower} then 1 else 0 end) as c{$i}");
            $query->selectRaw("coalesce(sum(case when {$lower} then final_amount else 0 end), 0) as v{$i}");
        }

        $row = $query->first();

        return collect($bands)->map(function ($b, $i) use ($row) {
            $count = (int) ($row->{"c{$i}"} ?? 0);
            $value = (float) ($row->{"v{$i}"} ?? 0);

            return [
                'band' => $b['band'],
                'bookings' => $count,
                'value' => $value,
                'average' => $count > 0 ? round($value / $count, 2) : null,
            ];
        })->all();
    }

    /**
     * Size bands grouped by the M2 plot-size master (plots with no size are
     * grouped under "Unsized"). One query.
     *
     * @return list<array{band:string, total:int, available:int, booked:int}>
     */
    public function sizeBands(ReportFilterData $filters, ?InventoryFilters $extras = null): array
    {
        $rows = $this->scopedPlots($filters, $extras)
            ->leftJoin('plot_sizes', 'plot_sizes.id', '=', 'plots.plot_size_id')
            ->groupBy('plots.plot_size_id', 'plot_sizes.name', 'plot_sizes.sort_order')
            ->orderByRaw('plot_sizes.sort_order is null, plot_sizes.sort_order, plot_sizes.name')
            ->selectRaw('plot_sizes.name as name, count(*) as total')
            ->selectRaw('sum(case when plots.status = ? then 1 else 0 end) as available', [PlotStatus::Available->value])
            ->selectRaw('sum(case when plots.status = ? then 1 else 0 end) as booked', [PlotStatus::Booked->value])
            ->get();

        return $rows->map(fn ($r) => [
            'band' => $r->name ?? 'Unsized',
            'total' => (int) $r->total,
            'available' => (int) $r->available,
            'booked' => (int) $r->booked,
        ])->all();
    }

    // --- internals -------------------------------------------------

    /**
     * @param  Collection<int, string>  $labels  id => label
     * @param  callable(int|string, string): array<string, mixed>  $identity
     * @return list<array<string, mixed>>
     */
    private function rollup(Collection $labels, string $groupColumn, ReportFilterData $filters, ?InventoryFilters $extras, callable $identity): array
    {
        if ($labels->isEmpty()) {
            return [];
        }
        $ids = $labels->keys()->all();
        $shortColumn = str_replace('plots.', '', $groupColumn);

        $counts = $this->scopedPlots($filters, $extras)
            ->whereIn($groupColumn, $ids)
            ->groupBy($groupColumn)
            ->selectRaw("{$groupColumn} as k, count(*) as total")
            ->selectRaw('sum(case when plots.status = ? then 1 else 0 end) as available', [PlotStatus::Available->value])
            ->selectRaw('sum(case when plots.status = ? then 1 else 0 end) as booked', [PlotStatus::Booked->value])
            ->selectRaw('sum(case when plots.status = ? then 1 else 0 end) as possession', [PlotStatus::PossessionCompleted->value])
            ->get()->keyBy('k');

        $registered = $this->registeredByColumn($shortColumn, $ids, $filters, $extras);

        $rows = [];
        foreach ($labels as $id => $name) {
            $total = (int) ($counts[$id]->total ?? 0);
            $available = (int) ($counts[$id]->available ?? 0);

            $rows[] = $identity($id, $name) + [
                'total' => $total,
                'available' => $available,
                'booked' => (int) ($counts[$id]->booked ?? 0),
                'registered' => (int) ($registered[$id] ?? 0),
                'possession' => (int) ($counts[$id]->possession ?? 0),
                'sold_pct' => $total > 0 ? round(($total - $available) / $total * 100, 1) : null,
            ];
        }

        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $rows;
    }

    private function registeredCount(ReportFilterData $filters, ?InventoryFilters $extras): int
    {
        return (int) $this->registeredBase($filters, $extras)->count(DB::raw('distinct plots.id'));
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, int>
     */
    private function registeredByColumn(string $shortColumn, array $ids, ReportFilterData $filters, ?InventoryFilters $extras): Collection
    {
        return $this->registeredBase($filters, $extras)
            ->whereIn("plots.{$shortColumn}", $ids)
            ->groupBy("plots.{$shortColumn}")
            ->selectRaw("plots.{$shortColumn} as k, count(distinct plots.id) as c")
            ->pluck('c', 'k');
    }

    /**
     * Plots whose confirmed booking has a COMPLETED registry case (M9).
     *
     * @return Builder
     */
    private function registeredBase(ReportFilterData $filters, ?InventoryFilters $extras)
    {
        $query = $this->scopedPlots($filters, $extras)
            ->join('bookings', function ($join): void {
                $join->on('bookings.plot_id', '=', 'plots.id')
                    ->whereNull('bookings.deleted_at')
                    ->where('bookings.status', '=', BookingStatus::Confirmed->value);
            })
            ->join('registry_cases', 'registry_cases.booking_id', '=', 'bookings.id')
            ->where('registry_cases.status', RegistryCaseStatus::Completed->value);

        return $query;
    }

    /**
     * Base scoped plot query (project / block / size range).
     *
     * The `plot_status` filter is deliberately NOT applied here — the KPIs,
     * distribution chart and roll-up tables must show the whole picture. It only
     * narrows the "available plots" drill-down list (see {@see availablePlots()}).
     *
     * @return Builder
     */
    private function scopedPlots(ReportFilterData $filters, ?InventoryFilters $extras)
    {
        $query = DB::table('plots')->where('plots.is_active', true)->whereNull('plots.deleted_at');
        ReportFilterScope::project($query, $filters, 'plots.project_id');
        ReportFilterScope::block($query, $filters, 'plots.block_id');
        $this->applySizeRange($query, $extras);

        return $query;
    }

    private function applySizeRange(mixed $query, ?InventoryFilters $extras): void
    {
        if ($extras === null) {
            return;
        }
        if ($extras->sizeMin !== null) {
            $query->where('plots.area', '>=', $extras->sizeMin);
        }
        if ($extras->sizeMax !== null) {
            $query->where('plots.area', '<=', $extras->sizeMax);
        }
    }
}
