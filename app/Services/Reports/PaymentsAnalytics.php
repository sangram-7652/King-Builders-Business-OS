<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\User;
use App\Queries\Reports\ReportFilterScope;
use App\Support\Reports\Concerns\FormatsReportMoney;
use App\Support\Reports\Concerns\MemoizesFilteredQueries;
use App\Support\Reports\ReportFilterData;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SQL-aggregated payment analytics for the executive dashboard (M11.2) and the
 * MIS report (M11.5) — booking-level M7 truth only.
 *
 * Payment Plans / Installments were decommissioned, so there is no longer an
 * "installment walk". Every figure here is derived straight from `bookings`
 * and `payments`, the same truth {@see \App\Services\Payments\PaymentLedger}
 * uses for a single booking:
 *
 *   collected(scope)    = Σ SUCCESS payments.amount on a confirmed (filtered) booking
 *   outstanding(scope)  = Σ confirmed booking.final_amount − Σ SUCCESS payments.amount
 *                         (a snapshot of the whole scoped book, not period-bound —
 *                          there is no due-date schedule left to bound it by)
 *
 * There is no more "receivable" (demand raised on a plan), "overdue" (past a
 * due date) or "collection efficiency" (collected / receivable) — those
 * concepts required an installment schedule that no longer exists.
 */
class PaymentsAnalytics
{
    use FormatsReportMoney;
    use MemoizesFilteredQueries;

    /**
     * @return array{
     *   bookingValue: float, collectedAllTime: float, collectedInPeriod: float,
     *   outstanding: float, collectionPercent: float|null,
     * }
     */
    public function summary(ReportFilterData $filters): array
    {
        $bookingValue = $this->reportMoney($this->confirmedBookings($filters)->sum('bookings.final_amount'));
        $collectedAllTime = $this->reportMoney($this->successPayments($filters)->sum('payments.amount'));
        $collectedInPeriod = $this->reportMoney(ReportFilterScope::dateRange(
            $this->successPayments($filters), $filters, 'payments.payment_date',
        )->sum('payments.amount'));

        return [
            'bookingValue' => $bookingValue,
            'collectedAllTime' => $collectedAllTime,
            'collectedInPeriod' => $collectedInPeriod,
            'outstanding' => $this->reportMoneyMinus($bookingValue, $collectedAllTime),
            'collectionPercent' => $bookingValue > 0.0
                ? round($collectedAllTime / $bookingValue * 100, 1)
                : null,
        ];
    }

    /**
     * The headline figures — a snapshot of the whole (filtered) book, ignoring
     * the date window (same semantics the decommissioned collections report
     * used for `kpis()`).
     *
     * @return array{collected: float, outstanding: float}
     */
    public function kpis(ReportFilterData $filters): array
    {
        return $this->remember('kpis', $filters, function () use ($filters) {
            $bookingValue = $this->reportMoney($this->confirmedBookings($filters)->sum('bookings.final_amount'));
            $collected = $this->reportMoney($this->successPayments($filters)->sum('payments.amount'));

            return [
                'collected' => $collected,
                'outstanding' => $this->reportMoneyMinus($bookingValue, $collected),
            ];
        });
    }

    /**
     * Per-day SUCCESS cash collected (by `payment_date`) for the window.
     * Keyed `Y-m-d`.
     *
     * @return array<string, float>
     */
    public function collectedSeries(ReportFilterData $filters): array
    {
        return $this->remember('collectedSeries', $filters, fn () => $this->periodCollected($filters)
            ->groupByRaw('date(payments.payment_date)')
            ->selectRaw('date(payments.payment_date) as d, coalesce(sum(payments.amount), 0) as v')
            ->pluck('v', 'd')
            ->map(fn ($v) => (float) $v)
            ->all());
    }

    /**
     * @return list<array{project_id:int, project:string, collected:float, outstanding:float}>
     */
    public function projectCollection(ReportFilterData $filters): array
    {
        $names = ReportFilterScope::project(
            DB::table('projects')->where('is_active', true), $filters, 'id',
        )->pluck('name', 'id');

        return $this->rollup($this->bookingLedgerByColumn($filters, 'project_id'), $names, fn ($id, $name) => [
            'project_id' => (int) $id, 'project' => (string) $name,
        ]);
    }

    /**
     * @return list<array{block_id:int, project_id:int, block:string, project:string, collected:float, outstanding:float}>
     */
    public function blockCollection(ReportFilterData $filters): array
    {
        $blocks = ReportFilterScope::block(
            ReportFilterScope::project(DB::table('blocks')->where('blocks.is_active', true), $filters, 'blocks.project_id'),
            $filters, 'blocks.id',
        )->join('projects', 'projects.id', '=', 'blocks.project_id')
            ->get(['blocks.id as id', 'blocks.name as name', 'blocks.project_id as project_id', 'projects.name as project_name'])
            ->keyBy('id');

        return $this->rollup(
            $this->bookingLedgerByColumn($filters, 'block_id'),
            $blocks->map(fn ($b) => $b->name),
            fn ($id, $name) => [
                'block_id' => (int) $id,
                'project_id' => (int) ($blocks[$id]->project_id ?? 0),
                'block' => (string) $name,
                'project' => (string) ($blocks[$id]->project_name ?? '—'),
            ],
        );
    }

    /**
     * @return list<array{user_id:int, name:string, collected:float, outstanding:float}>
     */
    public function salespersonCollection(ReportFilterData $filters, ?int $onlyId = null): array
    {
        $rows = $this->bookingLedgerByColumn($filters, 'created_by');
        if ($onlyId !== null) {
            $rows = $rows->filter(fn ($r, $k) => (int) $k === $onlyId);
        }
        if ($rows->isEmpty()) {
            return [];
        }

        $names = User::query()->whereIn('id', $rows->keys())->pluck('name', 'id');

        $out = $rows->map(fn ($r, $id) => [
            'user_id' => (int) $id,
            'name' => (string) ($names[$id] ?? 'Unknown'),
            'collected' => (float) $r->collected,
            'outstanding' => (float) $r->outstanding,
        ])->values()->all();

        usort($out, fn ($a, $b) => $b['outstanding'] <=> $a['outstanding']);

        return $out;
    }

    /**
     * Payment-method mix for SUCCESS payments in the window.
     *
     * @return list<array{method: string, amount: float, transactions: int, percent: float}>
     */
    public function paymentMethods(ReportFilterData $filters, ?int $modeId = null): array
    {
        $rows = $this->periodCollected($filters)
            ->join('payment_modes', 'payment_modes.id', '=', 'payments.payment_mode_id')
            ->when($modeId !== null, fn ($q) => $q->where('payment_modes.id', $modeId))
            ->groupBy('payment_modes.id', 'payment_modes.name')
            ->selectRaw('payment_modes.name as method, coalesce(sum(payments.amount), 0) as amount, count(*) as transactions')
            ->orderByDesc('amount')
            ->get();

        $total = (float) $rows->sum('amount');

        return $rows->map(fn ($r) => [
            'method' => (string) $r->method,
            'amount' => (float) $r->amount,
            'transactions' => (int) $r->transactions,
            'percent' => $total > 0.0 ? round((float) $r->amount / $total * 100, 1) : 0.0,
        ])->all();
    }

    // =====================================================================
    //  shared building blocks
    // =====================================================================

    /**
     * Booking-value + collected, grouped by a booking column. One aggregate
     * query — the booking-level replacement for the old installment ledger
     * rollup.
     *
     * @return Collection<int, object>
     */
    private function bookingLedgerByColumn(ReportFilterData $filters, string $column): Collection
    {
        // Two separate aggregates: booking_value must not multiply per matching
        // payment row, so it is summed independently of the payments join.
        $values = DB::query()
            ->fromSub($this->confirmedBookings($filters)->select("bookings.{$column} as k", 'bookings.final_amount'), 'b')
            ->whereNotNull('b.k')
            ->groupBy('b.k')
            ->selectRaw('b.k as k, coalesce(sum(b.final_amount), 0) as booking_value')
            ->pluck('booking_value', 'k');

        $collected = DB::query()
            ->fromSub($this->confirmedBookings($filters)->select('bookings.id', "bookings.{$column} as k"), 'b')
            ->join('payments as p', function ($j): void {
                $j->on('p.booking_id', '=', 'b.id')
                    ->whereNull('p.deleted_at')
                    ->where('p.status', '=', PaymentStatus::Success->value);
            })
            ->whereNotNull('b.k')
            ->groupBy('b.k')
            ->selectRaw('b.k as k, coalesce(sum(p.amount), 0) as collected')
            ->pluck('collected', 'k');

        return $values->keys()->mapWithKeys(function ($k) use ($values, $collected) {
            $bookingValue = (float) $values[$k];
            $col = (float) ($collected[$k] ?? 0);

            return [$k => (object) [
                'collected' => $col,
                'outstanding' => max(0.0, round($bookingValue - $col, 2)),
            ]];
        });
    }

    /** Confirmed bookings matching the project / block / salesperson filters. */
    private function confirmedBookings(ReportFilterData $filters): Builder
    {
        $query = DB::table('bookings')->where('bookings.status', BookingStatus::Confirmed->value);
        ReportFilterScope::notDeleted($query, 'bookings');
        ReportFilterScope::project($query, $filters, 'bookings.project_id');
        ReportFilterScope::block($query, $filters, 'bookings.block_id');
        ReportFilterScope::salesperson($query, $filters, 'bookings.created_by');

        return $query;
    }

    /** SUCCESS payments on confirmed (filtered) bookings — all-time. */
    private function successPayments(ReportFilterData $filters): Builder
    {
        return DB::table('payments')
            ->joinSub($this->confirmedBookings($filters)->select('bookings.id as id'), 'cb', 'cb.id', '=', 'payments.booking_id')
            ->whereNull('payments.deleted_at')
            ->where('payments.status', PaymentStatus::Success->value);
    }

    /** SUCCESS payments on confirmed bookings, restricted to the date window. */
    private function periodCollected(ReportFilterData $filters): Builder
    {
        return ReportFilterScope::dateRange($this->successPayments($filters), $filters, 'payments.payment_date');
    }

    /**
     * Merge a `bookingLedgerByColumn` result with a name map into display rows.
     *
     * @param  Collection<int, object>  $ledger
     * @param  Collection<int, string>  $names
     * @param  callable(int|string, string): array<string, mixed>  $identity
     * @return list<array<string, mixed>>
     */
    private function rollup(Collection $ledger, Collection $names, callable $identity): array
    {
        if ($names->isEmpty()) {
            return [];
        }

        $rows = [];
        foreach ($names as $id => $name) {
            $r = $ledger[$id] ?? null;

            $rows[] = $identity($id, $name) + [
                'collected' => (float) ($r->collected ?? 0),
                'outstanding' => (float) ($r->outstanding ?? 0),
            ];
        }

        usort($rows, fn ($a, $b) => $b['outstanding'] <=> $a['outstanding']);

        return $rows;
    }
}
