<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\AgingBucket;
use App\Enums\BookingStatus;
use App\Enums\ChequeStatus;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentPlanStatus;
use App\Enums\PaymentStatus;
use App\Models\Buyer;
use App\Models\User;
use App\Queries\Reports\ReportFilterScope;
use App\Services\Collections\AgingCalculator;
use App\Services\Payments\PaymentLedger;
use App\Support\Reports\ChartSeries;
use App\Support\Reports\CollectionFilters;
use App\Support\Reports\Concerns\FormatsReportMoney;
use App\Support\Reports\Concerns\MemoizesFilteredQueries;
use App\Support\Reports\Granularity;
use App\Support\Reports\ReportFilterData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SQL-aggregated collection analytics for the executive dashboard (M11.2) and
 * the collections report (M11.4).
 *
 * This is NOT a second financial engine — it applies the exact M7/M8
 * definitions as SQL aggregates instead of looping the
 * {@see PaymentLedger} / {@see AgingCalculator} per
 * booking. The one source of truth for the report is
 * {@see self::installmentLedger()}: one row per non-waived installment on a
 * live (draft|active) plan of a CONFIRMED booking, with `paid` = Σ allocations
 * from SUCCESS payments only (`PaymentLedger::installmentPaid`).
 *
 *   receivable  = Σ installment.amount                        (demand raised)
 *   collected   = Σ min(paid, amount)                         (= receivable − outstanding)
 *   outstanding = Σ max(amount − paid, 0)                     (M8 installmentOutstanding)
 *   overdue     = Σ max(amount − paid, 0) where due_date < today
 *   efficiency  = receivable > 0 ? (receivable − outstanding)/receivable*100 : null
 *
 * "Cash collected" (Σ SUCCESS payments.amount, incl. unallocated) is reported
 * separately in the Booking-vs-Collection reconciliation — a bounced cheque is
 * FAILED / REVERSED in M7 and therefore never contributes.
 */
class CollectionAnalytics
{
    use FormatsReportMoney;
    use MemoizesFilteredQueries;

    // =====================================================================
    //  M11.2 executive-dashboard summary
    // =====================================================================

    /**
     * @return array{
     *   bookingValue: float, collectedAllTime: float, collectedInPeriod: float,
     *   outstanding: float, overdue: float, expectedInPeriod: float, collectionPercent: float|null,
     * }
     */
    public function summary(ReportFilterData $filters): array
    {
        $bookingValue = $this->reportMoney($this->confirmedBookings($filters)->sum('bookings.final_amount'));

        $collectedAllTime = $this->reportMoney($this->successPayments($filters)->sum('payments.amount'));

        $collectedInPeriod = $this->reportMoney(ReportFilterScope::dateRange(
            $this->successPayments($filters), $filters, 'payments.payment_date',
        )->sum('payments.amount'));

        $expectedInPeriod = $this->reportMoney(ReportFilterScope::dateRange(
            $this->liveInstallments($filters), $filters, 'i.due_date',
        )->sum('i.amount'));

        return [
            'bookingValue' => $bookingValue,
            'collectedAllTime' => $collectedAllTime,
            'collectedInPeriod' => $collectedInPeriod,
            // M8 truth — the installment walk (Σ max(amount − SUCCESS-allocated, 0)),
            // NOT "booking value − collected".
            'outstanding' => $this->kpis($filters)['outstanding'],
            'overdue' => $this->overdueAmount($filters),
            'expectedInPeriod' => $expectedInPeriod,
            'collectionPercent' => $bookingValue > 0.0
                ? round($collectedAllTime / $bookingValue * 100, 1)
                : null,
        ];
    }

    /** Distinct confirmed bookings that currently carry an overdue balance. */
    public function overdueBookingCount(ReportFilterData $filters): int
    {
        return (int) DB::query()
            ->fromSub($this->overduePerInstallment($filters), 'x')
            ->where('x.amount', '>', DB::raw('x.paid'))
            ->distinct()
            ->count('x.booking_id');
    }

    // =====================================================================
    //  M11.4 collections report
    // =====================================================================

    /**
     * The five headline figures. One aggregate query over the installment
     * ledger — a snapshot of the whole book (respects project / block /
     * salesperson, ignores the date window).
     *
     * @return array{receivable: float, collected: float, outstanding: float, overdue: float, efficiency: float|null}
     */
    public function kpis(ReportFilterData $filters): array
    {
        return $this->remember('kpis', $filters, function () use ($filters) {
            $row = DB::query()
                ->fromSub($this->installmentLedger($filters), 'x')
                ->selectRaw('coalesce(sum(x.amount), 0) as receivable')
                ->selectRaw('coalesce(sum(case when x.paid >= x.amount then x.amount else x.paid end), 0) as collected')
                ->selectRaw('coalesce(sum(case when x.amount > x.paid then x.amount - x.paid else 0 end), 0) as outstanding')
                ->selectRaw('coalesce(sum(case when x.amount > x.paid and x.due_date < ? then x.amount - x.paid else 0 end), 0) as overdue', [$this->today()])
                ->first();

            $receivable = $this->reportMoney($row->receivable ?? 0);
            $outstanding = $this->reportMoney($row->outstanding ?? 0);

            return [
                'receivable' => $receivable,
                'collected' => $this->reportMoney($row->collected ?? 0),
                'outstanding' => $outstanding,
                'overdue' => $this->reportMoney($row->overdue ?? 0),
                'efficiency' => $receivable > 0.0 ? round(($receivable - $outstanding) / $receivable * 100, 1) : null,
            ];
        });
    }

    /**
     * Cash actually received in the window — Σ SUCCESS `payments.amount` by
     * `payment_date`. Bounced cheques are FAILED / REVERSED in M7 so never
     * count. Period-scoped (unlike {@see self::reconciliation()} cash, which is
     * all-time for the booking-value comparison).
     */
    public function cashCollectedInPeriod(ReportFilterData $filters): float
    {
        return $this->remember('cashCollectedInPeriod', $filters, fn () => (float) $this->periodCollected($filters)->sum('payments.amount'));
    }

    /**
     * Booking value vs receivable vs cash collected vs outstanding — the
     * reconciliation that makes clear "booking value" is not "cash".
     *
     * @return array{bookingValue: float, receivable: float, cashCollected: float, outstanding: float, unallocated: float}
     */
    public function reconciliation(ReportFilterData $filters): array
    {
        $bookingValue = $this->reportMoney($this->confirmedBookings($filters)->sum('bookings.final_amount'));
        $cash = $this->reportMoney($this->successPayments($filters)->sum('payments.amount'));
        $k = $this->kpis($filters);

        return [
            'bookingValue' => $bookingValue,
            'receivable' => $k['receivable'],
            'cashCollected' => $cash,
            'outstanding' => $k['outstanding'],
            'unallocated' => max(0.0, $this->reportMoneyMinus($cash, $k['collected'])),
        ];
    }

    /**
     * Ageing of overdue receivables by bucket (M8 boundaries). Two queries —
     * amounts/counts in one, distinct-customer per bucket in the other.
     *
     * @return list<array{bucket: string, label: string, outstanding: float, installments: int, customers: int}>
     */
    public function ageing(ReportFilterData $filters): array
    {
        $today = $this->today();

        $row = DB::query()
            ->fromSub($this->installmentLedger($filters), 'x')
            ->where('x.amount', '>', DB::raw('x.paid'))
            ->whereRaw('x.due_date < ?', [$today])
            ->selectRaw(...$this->bucketSums('outstanding', 'x.amount - x.paid'))
            ->selectRaw(...$this->bucketSums('inst', '1'))
            ->first();

        $customers = DB::query()
            ->fromSub($this->installmentLedger($filters), 'x')
            ->where('x.amount', '>', DB::raw('x.paid'))
            ->whereRaw('x.due_date < ?', [$today])
            ->selectRaw($this->bucketCaseSql(), $this->bucketThresholds())
            ->selectRaw('count(distinct coalesce(x.buyer_id, -x.booking_id)) as c')
            ->groupBy('bucket')
            ->pluck('c', 'bucket');

        return collect(AgingBucket::cases())->map(function (AgingBucket $b, int $i) use ($row, $customers) {
            return [
                'bucket' => $b->value,
                'label' => $b->label(),
                'outstanding' => (float) ($row->{"outstanding{$i}"} ?? 0),
                'installments' => (int) ($row->{"inst{$i}"} ?? 0),
                'customers' => (int) ($customers[(string) $i] ?? 0),
            ];
        })->all();
    }

    /**
     * Collected / receivable / outstanding over the window, plus the running
     * collection-efficiency line. Daily SQL aggregate folded into buckets.
     *
     * @return array{trend: ChartSeries, efficiency: ChartSeries}
     */
    public function trend(ReportFilterData $filters): array
    {
        $g = Granularity::for($filters);

        $collected = $this->fold($this->collectedDailyRows($filters), $g);
        $receivable = $this->fold($this->receivableDailyRows($filters), $g);

        $points = [];
        $effPoints = [];
        $cumC = 0.0;
        $cumR = 0.0;

        foreach (Granularity::buckets($filters, $g) as [$key, $label]) {
            $c = $collected[$key] ?? 0.0;
            $r = $receivable[$key] ?? 0.0;
            $cumC += $c;
            $cumR += $r;

            $points[] = ['label' => $label, 'key' => $key, 'values' => [
                'collected' => $c,
                'receivable' => $r,
                'outstanding' => max(0.0, round($cumR - $cumC, 2)),
            ]];

            $eff = $cumR > 0.0 ? round(min(999.9, $cumC / $cumR * 100), 1) : 0.0;
            $effPoints[] = ['label' => $label, 'key' => $key, 'values' => ['efficiency' => $eff]];
        }

        return [
            'trend' => new ChartSeries($points, $g, ['collected', 'receivable', 'outstanding']),
            'efficiency' => new ChartSeries($effPoints, $g, ['efficiency']),
        ];
    }

    /**
     * Per-calendar-month receivable / collected / outstanding / efficiency
     * (due-based receivable vs payment-date-based cash). Two queries.
     *
     * @return list<array{month: string, receivable: float, collected: float, outstanding: float, efficiency: float|null}>
     */
    public function monthly(ReportFilterData $filters): array
    {
        $collected = $this->foldToMonths($this->collectedDailyRows($filters));
        $receivable = $this->foldToMonths($this->receivableDailyRows($filters));

        return collect(Granularity::months($filters))->map(function ($m) use ($collected, $receivable) {
            [$key, $label] = $m;
            $r = (float) ($receivable[$key] ?? 0);
            $c = (float) ($collected[$key] ?? 0);

            return [
                'month' => $label,
                'receivable' => $r,
                'collected' => $c,
                'outstanding' => round($r - $c, 2),
                'efficiency' => $r > 0.0 ? round($c / $r * 100, 1) : null,
            ];
        })->all();
    }

    /**
     * Receivable / collected / outstanding / overdue grouped by a booking
     * column (project_id, block_id, created_by). One aggregate query + one
     * name lookup.
     *
     * @return Collection<int, object> keyed by the group id
     */
    private function ledgerByColumn(ReportFilterData $filters, string $column): Collection
    {
        return DB::query()
            ->fromSub($this->installmentLedger($filters), 'x')
            ->whereNotNull("x.{$column}")
            ->groupBy("x.{$column}")
            ->selectRaw("x.{$column} as k")
            ->selectRaw('coalesce(sum(x.amount), 0) as receivable')
            ->selectRaw('coalesce(sum(case when x.paid >= x.amount then x.amount else x.paid end), 0) as collected')
            ->selectRaw('coalesce(sum(case when x.amount > x.paid then x.amount - x.paid else 0 end), 0) as outstanding')
            ->selectRaw('coalesce(sum(case when x.amount > x.paid and x.due_date < ? then x.amount - x.paid else 0 end), 0) as overdue', [$this->today()])
            ->selectRaw('count(distinct coalesce(x.buyer_id, -x.booking_id)) as customers')
            ->get()->keyBy('k');
    }

    /**
     * @return list<array{project_id:int, project:string, receivable:float, collected:float, outstanding:float, overdue:float, efficiency:float|null}>
     */
    public function projectCollection(ReportFilterData $filters): array
    {
        $names = ReportFilterScope::project(
            DB::table('projects')->where('is_active', true), $filters, 'id',
        )->pluck('name', 'id');

        return $this->rollup($this->ledgerByColumn($filters, 'project_id'), $names, fn ($id, $name) => [
            'project_id' => (int) $id, 'project' => (string) $name,
        ]);
    }

    /**
     * @return list<array{block_id:int, project_id:int, block:string, project:string, receivable:float, collected:float, outstanding:float, overdue:float, efficiency:float|null}>
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
            $this->ledgerByColumn($filters, 'block_id'),
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
     * @return list<array{user_id:int, name:string, customers:int, receivable:float, collected:float, outstanding:float, overdue:float, efficiency:float|null}>
     */
    public function salespersonCollection(ReportFilterData $filters, ?int $onlyId = null): array
    {
        $rows = $this->ledgerByColumn($filters, 'created_by');
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
            'customers' => (int) $r->customers,
            'receivable' => (float) $r->receivable,
            'collected' => (float) $r->collected,
            'outstanding' => (float) $r->outstanding,
            'overdue' => (float) $r->overdue,
            'efficiency' => (float) $r->receivable > 0.0 ? round((((float) $r->receivable - (float) $r->outstanding) / (float) $r->receivable) * 100, 1) : null,
        ])->values()->all();

        usort($out, fn ($a, $b) => $b['outstanding'] <=> $a['outstanding']);

        return $out;
    }

    /**
     * Top customers by total outstanding and (separately) by overdue.
     *
     * @return array{outstanding: list<array<string,mixed>>, overdue: list<array<string,mixed>>}
     */
    public function topCustomers(ReportFilterData $filters, int $limit = 5): array
    {
        $rows = DB::query()
            ->fromSub($this->installmentLedger($filters), 'x')
            ->whereNotNull('x.buyer_id')
            ->groupBy('x.buyer_id')
            ->selectRaw('x.buyer_id as bid')
            ->selectRaw('coalesce(sum(case when x.amount > x.paid then x.amount - x.paid else 0 end), 0) as outstanding')
            ->selectRaw('coalesce(sum(case when x.amount > x.paid and x.due_date < ? then x.amount - x.paid else 0 end), 0) as overdue', [$this->today()])
            ->get();

        if ($rows->isEmpty()) {
            return ['outstanding' => [], 'overdue' => []];
        }

        $names = Buyer::query()->whereIn('id', $rows->pluck('bid'))
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'customer_code'])->keyBy('id');

        $map = fn (string $metric) => $rows->sortByDesc($metric)->take($limit)
            ->filter(fn ($r) => (float) $r->{$metric} > 0.0)
            ->map(fn ($r) => [
                'buyer_id' => (int) $r->bid,
                'name' => $names[$r->bid]?->fullName() ?? '—',
                'customer_code' => $names[$r->bid]?->customer_code ?? '—',
                'amount' => (float) $r->{$metric},
            ])->values()->all();

        return ['outstanding' => $map('outstanding'), 'overdue' => $map('overdue')];
    }

    /**
     * Expected (future) dues — next 7 / 30 days. One query.
     *
     * @return array{next7: float, next30: float}
     */
    public function expected(ReportFilterData $filters): array
    {
        $today = $this->today();
        $row = DB::query()
            ->fromSub($this->installmentLedger($filters), 'x')
            ->where('x.amount', '>', DB::raw('x.paid'))
            ->whereRaw('x.due_date >= ?', [$today])
            ->selectRaw('coalesce(sum(case when x.due_date <= ? then x.amount - x.paid else 0 end), 0) as n7', [$this->today(7)])
            ->selectRaw('coalesce(sum(case when x.due_date <= ? then x.amount - x.paid else 0 end), 0) as n30', [$this->today(30)])
            ->first();

        return ['next7' => (float) ($row->n7 ?? 0), 'next30' => (float) ($row->n30 ?? 0)];
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

    /**
     * Cheque lifecycle counts + bounced amount. Uses `payments.cheque_status`
     * (M7) + `cheque_bounces` (M8). A bounced cheque's payment is FAILED /
     * REVERSED so its amount is already excluded from `collected`.
     *
     * @return array{received: int, cleared: int, pending: int, bounced: int, bounced_amount: float, bank_charges: float}
     */
    public function cheques(ReportFilterData $filters): array
    {
        $base = $this->scopedPayments($filters)->whereNotNull('payments.cheque_status');

        $row = (clone $base)
            ->selectRaw('count(*) as received')
            ->selectRaw('sum(case when payments.cheque_status = ? then 1 else 0 end) as cleared', [ChequeStatus::Cleared->value])
            ->selectRaw('sum(case when payments.cheque_status = ? then 1 else 0 end) as pending', [ChequeStatus::Pending->value])
            ->selectRaw('sum(case when payments.cheque_status = ? then 1 else 0 end) as bounced', [ChequeStatus::Bounced->value])
            ->first();

        $bounce = DB::table('cheque_bounces')
            ->join('payments', 'payments.id', '=', 'cheque_bounces.payment_id')
            ->joinSub($this->confirmedBookings($filters)->select('bookings.id as id'), 'cb', 'cb.id', '=', 'payments.booking_id')
            ->whereNull('payments.deleted_at')
            ->selectRaw('coalesce(sum(payments.amount), 0) as amt, coalesce(sum(cheque_bounces.bank_charges), 0) as charges')
            ->first();

        return [
            'received' => (int) ($row->received ?? 0),
            'cleared' => (int) ($row->cleared ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'bounced' => (int) ($row->bounced ?? 0),
            'bounced_amount' => (float) ($bounce->amt ?? 0),
            'bank_charges' => (float) ($bounce->charges ?? 0),
        ];
    }

    /**
     * Customer recovery report — one row per OVERDUE installment. Paginated,
     * name-joined (no N+1). `$bucket` narrows to one ageing band.
     *
     * @return LengthAwarePaginator<int, object>
     */
    public function recoveryReport(
        ReportFilterData $filters,
        CollectionFilters $extras,
        string $sort = 'days_overdue',
        int $perPage = 25,
    ): LengthAwarePaginator {
        $today = $this->today();

        $query = DB::table('installments as i')
            ->join('payment_plans as pp', function ($j): void {
                $j->on('pp.id', '=', 'i.payment_plan_id')
                    ->whereNull('pp.deleted_at')
                    ->whereIn('pp.status', [PaymentPlanStatus::Draft->value, PaymentPlanStatus::Active->value]);
            })
            ->join('bookings as b', function ($j): void {
                $j->on('b.id', '=', 'pp.booking_id')
                    ->whereNull('b.deleted_at')
                    ->where('b.status', '=', BookingStatus::Confirmed->value);
            })
            ->join('projects as pr', 'pr.id', '=', 'b.project_id')
            ->leftJoin('booking_buyers as bb', function ($j): void {
                $j->on('bb.booking_id', '=', 'b.id')->where('bb.is_primary', '=', true);
            })
            ->leftJoin('buyers as byr', 'byr.id', '=', 'bb.buyer_id')
            ->leftJoin('payment_allocations as pa', 'pa.installment_id', '=', 'i.id')
            ->leftJoin('payments as p', function ($j): void {
                $j->on('p.id', '=', 'pa.payment_id')
                    ->whereNull('p.deleted_at')
                    ->where('p.status', '=', PaymentStatus::Success->value);
            })
            ->where('i.status', '!=', InstallmentStatus::Waived->value)
            ->whereRaw('i.due_date < ?', [$today])
            ->groupBy('i.id', 'i.installment_number', 'i.name', 'i.due_date', 'i.amount',
                'b.id', 'b.booking_number', 'pr.name', 'byr.id', 'byr.first_name', 'byr.middle_name', 'byr.last_name')
            ->havingRaw('i.amount > coalesce(sum(case when p.id is not null then pa.amount else 0 end), 0)')
            ->selectRaw('i.id as installment_id, i.installment_number, i.name as installment_name, i.due_date, i.amount as due_amount')
            ->selectRaw('coalesce(sum(case when p.id is not null then pa.amount else 0 end), 0) as paid')
            ->selectRaw('i.amount - coalesce(sum(case when p.id is not null then pa.amount else 0 end), 0) as outstanding')
            ->selectRaw('b.id as booking_id, b.booking_number, pr.name as project')
            ->selectRaw('byr.id as buyer_id, byr.first_name, byr.middle_name, byr.last_name');

        ReportFilterScope::project($query, $filters, 'b.project_id');
        ReportFilterScope::block($query, $filters, 'b.block_id');
        ReportFilterScope::salesperson($query, $filters, 'b.created_by');

        // Ageing bucket → a due-date window (days-overdue is derived in PHP;
        // "more overdue" == "older due date", so we order by due_date).
        if ($extras->bucket !== null) {
            [$lo, $hi] = $this->bucketBounds($extras->bucket);
            $query->whereRaw('i.due_date <= ?', [$this->today(-$lo)]);          // ≥ $lo days overdue
            if ($hi !== null) {
                $query->whereRaw('i.due_date >= ?', [$this->today(-$hi)]);      // ≤ $hi days overdue
            }
        }

        $query = $sort === 'outstanding'
            ? $query->orderByDesc('outstanding')->orderBy('i.due_date')
            : $query->orderBy('i.due_date')->orderByDesc('outstanding'); // oldest first == most overdue

        $page = $query->paginate($perPage);

        // Derive days_overdue for the (≤ perPage) displayed rows.
        $ref = CarbonImmutable::parse($today);
        $page->getCollection()->transform(function ($r) use ($ref) {
            $r->due_amount = (float) $r->due_amount;
            $r->paid = (float) $r->paid;
            $r->outstanding = (float) $r->outstanding;
            $r->days_overdue = (int) CarbonImmutable::parse((string) $r->due_date)->startOfDay()->diffInDays($ref);

            return $r;
        });

        return $page;
    }

    /**
     * Per-day demand for the window (installments *due* on that day), split
     * into raised / still-unpaid / now-overdue. Keyed `Y-m-d`. One SQL
     * aggregate over the installment ledger — the same M7/M8 truth every other
     * figure uses, exposed for the MIS daily / monthly tables (M11.5).
     *
     * @return array<string, array{receivable: float, outstanding: float, overdue: float}>
     */
    public function demandSeries(ReportFilterData $filters): array
    {
        return $this->remember('demandSeries', $filters, function () use ($filters) {
            $rows = DB::query()
                ->fromSub($this->installmentLedger($filters), 'x')
                ->whereBetween('x.due_date', [$filters->from->toDateString(), $filters->to->toDateString()])
                ->groupByRaw('date(x.due_date)')
                ->selectRaw('date(x.due_date) as d')
                ->selectRaw('coalesce(sum(x.amount), 0) as receivable')
                ->selectRaw('coalesce(sum(case when x.amount > x.paid then x.amount - x.paid else 0 end), 0) as outstanding')
                ->selectRaw('coalesce(sum(case when x.amount > x.paid and x.due_date < ? then x.amount - x.paid else 0 end), 0) as overdue', [$this->today()])
                ->get();

            $out = [];
            foreach ($rows as $r) {
                $out[(string) $r->d] = [
                    'receivable' => (float) $r->receivable,
                    'outstanding' => (float) $r->outstanding,
                    'overdue' => (float) $r->overdue,
                ];
            }

            return $out;
        });
    }

    /**
     * Per-day SUCCESS cash collected (by `payment_date`) for the window.
     * Keyed `Y-m-d`. Bounced cheques are FAILED/REVERSED in M7 so never appear.
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

    // =====================================================================
    //  shared building blocks
    // =====================================================================

    /**
     * One row per non-waived installment on a live plan of a confirmed
     * (filtered) booking, with `paid` = Σ SUCCESS allocations and the primary
     * buyer id. THE source of truth for every M11.4 aggregate.
     */
    private function installmentLedger(ReportFilterData $filters): Builder
    {
        $query = DB::table('installments as i')
            ->join('payment_plans as pp', function ($j): void {
                $j->on('pp.id', '=', 'i.payment_plan_id')
                    ->whereNull('pp.deleted_at')
                    ->whereIn('pp.status', [PaymentPlanStatus::Draft->value, PaymentPlanStatus::Active->value]);
            })
            ->join('bookings as b', function ($j): void {
                $j->on('b.id', '=', 'pp.booking_id')
                    ->whereNull('b.deleted_at')
                    ->where('b.status', '=', BookingStatus::Confirmed->value);
            })
            ->leftJoin('booking_buyers as bb', function ($j): void {
                $j->on('bb.booking_id', '=', 'b.id')->where('bb.is_primary', '=', true);
            })
            ->leftJoin('payment_allocations as pa', 'pa.installment_id', '=', 'i.id')
            ->leftJoin('payments as p', function ($j): void {
                $j->on('p.id', '=', 'pa.payment_id')
                    ->whereNull('p.deleted_at')
                    ->where('p.status', '=', PaymentStatus::Success->value);
            })
            ->where('i.status', '!=', InstallmentStatus::Waived->value)
            ->groupBy('i.id', 'i.amount', 'i.due_date', 'b.id', 'b.project_id', 'b.block_id', 'b.created_by', 'bb.buyer_id')
            ->selectRaw('i.amount as amount, i.due_date as due_date')
            ->selectRaw('b.id as booking_id, b.project_id as project_id, b.block_id as block_id, b.created_by as created_by')
            ->selectRaw('bb.buyer_id as buyer_id')
            ->selectRaw('coalesce(sum(case when p.id is not null then pa.amount else 0 end), 0) as paid');

        ReportFilterScope::project($query, $filters, 'b.project_id');
        ReportFilterScope::block($query, $filters, 'b.block_id');
        ReportFilterScope::salesperson($query, $filters, 'b.created_by');

        return $query;
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

    /** All payments (any status) on confirmed (filtered) bookings — all-time. */
    private function scopedPayments(ReportFilterData $filters): Builder
    {
        return DB::table('payments')
            ->joinSub($this->confirmedBookings($filters)->select('bookings.id as id'), 'cb', 'cb.id', '=', 'payments.booking_id')
            ->whereNull('payments.deleted_at');
    }

    /** SUCCESS payments on confirmed bookings, restricted to the date window. */
    private function periodCollected(ReportFilterData $filters): Builder
    {
        return ReportFilterScope::dateRange($this->successPayments($filters), $filters, 'payments.payment_date');
    }

    /** Non-waived installments on the live plan of a confirmed (filtered) booking. */
    private function liveInstallments(ReportFilterData $filters): Builder
    {
        $query = DB::table('installments as i')
            ->join('payment_plans as pp', 'pp.id', '=', 'i.payment_plan_id')
            ->joinSub($this->confirmedBookings($filters)->select('bookings.id as id'), 'cb', 'cb.id', '=', 'pp.booking_id')
            ->whereNull('pp.deleted_at')
            ->whereIn('pp.status', [PaymentPlanStatus::Draft->value, PaymentPlanStatus::Active->value])
            ->where('i.status', '!=', InstallmentStatus::Waived->value);

        return $query;
    }

    /**
     * Daily SUCCESS cash (by `payment_date`) in the window — the shared source
     * for {@see self::trend()} and {@see self::monthly()}. Memoised so one page
     * render does not fire it twice.
     *
     * @return Collection<int, object>
     */
    private function collectedDailyRows(ReportFilterData $filters): Collection
    {
        return $this->remember('collectedDailyRows', $filters, fn () => $this->periodCollected($filters)
            ->selectRaw('date(payments.payment_date) as d, coalesce(sum(payments.amount), 0) as v')
            ->groupBy('d')->get());
    }

    /**
     * Daily gross installment demand (by `due_date`) in the window — the shared
     * source for {@see self::trend()} and {@see self::monthly()}.
     *
     * @return Collection<int, object>
     */
    private function receivableDailyRows(ReportFilterData $filters): Collection
    {
        return $this->remember('receivableDailyRows', $filters, fn () => ReportFilterScope::dateRange(
            $this->liveInstallments($filters), $filters, 'i.due_date',
        )->selectRaw('date(i.due_date) as d, coalesce(sum(i.amount), 0) as v')->groupBy('d')->get());
    }

    /**
     * One row per past-due, non-waived installment: amount + SUCCESS-paid.
     * (M11.2 dashboard helper — kept for `overdueBookingCount`.)
     */
    private function overduePerInstallment(ReportFilterData $filters): Builder
    {
        return DB::table('installments as i')
            ->join('payment_plans as pp', 'pp.id', '=', 'i.payment_plan_id')
            ->joinSub($this->confirmedBookings($filters)->select('bookings.id as id'), 'cb', 'cb.id', '=', 'pp.booking_id')
            ->leftJoin('payment_allocations as pa', 'pa.installment_id', '=', 'i.id')
            ->leftJoin('payments as p', function ($join): void {
                $join->on('p.id', '=', 'pa.payment_id')
                    ->whereNull('p.deleted_at')
                    ->where('p.status', '=', PaymentStatus::Success->value);
            })
            ->whereNull('pp.deleted_at')
            ->whereIn('pp.status', [PaymentPlanStatus::Draft->value, PaymentPlanStatus::Active->value])
            ->where('i.status', '!=', InstallmentStatus::Waived->value)
            ->whereDate('i.due_date', '<', $this->today())
            ->groupBy('i.id', 'i.amount', 'pp.booking_id')
            ->selectRaw('pp.booking_id as booking_id, i.amount as amount, coalesce(sum(case when p.id is not null then pa.amount else 0 end), 0) as paid');
    }

    private function overdueAmount(ReportFilterData $filters): float
    {
        return $this->reportMoney(DB::query()
            ->fromSub($this->overduePerInstallment($filters), 'x')
            ->selectRaw('coalesce(sum(case when x.amount > x.paid then x.amount - x.paid else 0 end), 0) as overdue')
            ->value('overdue'));
    }

    // --- date + bucket helpers ------------------------------------------

    private function today(int $offsetDays = 0): string
    {
        return CarbonImmutable::today(config('app.timezone'))->addDays($offsetDays)->toDateString();
    }

    /**
     * Inclusive [low, high] day-overdue bounds for a bucket (high null = open).
     *
     * @return array{0: int, 1: int|null}
     */
    private function bucketBounds(AgingBucket $bucket): array
    {
        // Single source: App\Enums\AgingBucket::dayBounds() (F-M8-1).
        return $bucket->bounds();
    }

    /**
     * The threshold dates (today − 30/60/90/180), in the order the bucket SQL
     * binds them — derived from {@see AgingBucket::thresholds()} so the day
     * numbers are never duplicated here.
     *
     * @return list<string>
     */
    private function bucketThresholds(): array
    {
        return array_map(fn (int $days): string => $this->today(-$days), AgingBucket::thresholds());
    }

    /**
     * A `CASE ... as bucket` expression returning the ageing-bucket index
     * (0..4) for `x.due_date`. Binds {@see self::bucketThresholds()}.
     */
    private function bucketCaseSql(): string
    {
        return 'case
            when x.due_date >= ? then 0
            when x.due_date >= ? then 1
            when x.due_date >= ? then 2
            when x.due_date >= ? then 3
            else 4 end as bucket';
    }

    /**
     * A `selectRaw($sql, $bindings)` pair: five
     * `coalesce(sum(case ...)) as <prefix><i>` columns, one per ageing bucket.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function bucketSums(string $prefix, string $expr): array
    {
        $t = $this->bucketThresholds();
        $sql = implode(', ', [
            "coalesce(sum(case when x.due_date >= ? then {$expr} else 0 end), 0) as {$prefix}0",
            "coalesce(sum(case when x.due_date < ? and x.due_date >= ? then {$expr} else 0 end), 0) as {$prefix}1",
            "coalesce(sum(case when x.due_date < ? and x.due_date >= ? then {$expr} else 0 end), 0) as {$prefix}2",
            "coalesce(sum(case when x.due_date < ? and x.due_date >= ? then {$expr} else 0 end), 0) as {$prefix}3",
            "coalesce(sum(case when x.due_date < ? then {$expr} else 0 end), 0) as {$prefix}4",
        ]);

        return [$sql, [$t[0], $t[0], $t[1], $t[1], $t[2], $t[2], $t[3], $t[3]]];
    }

    /**
     * Fold a daily `{d, v}` collection into `[bucketKey => float]`.
     *
     * @param  Collection<int, object>  $daily
     * @return array<string, float>
     */
    private function fold(Collection $daily, string $granularity): array
    {
        $acc = [];
        foreach ($daily as $r) {
            $key = Granularity::keyFor(CarbonImmutable::parse((string) $r->d), $granularity);
            $acc[$key] = ($acc[$key] ?? 0.0) + (float) $r->v;
        }

        return $acc;
    }

    /**
     * Fold a daily `{d, v}` collection into `['YYYY-MM' => float]`.
     *
     * @param  Collection<int, object>  $daily
     * @return array<string, float>
     */
    private function foldToMonths(Collection $daily): array
    {
        $acc = [];
        foreach ($daily as $r) {
            $key = CarbonImmutable::parse((string) $r->d)->format('Y-m');
            $acc[$key] = ($acc[$key] ?? 0.0) + (float) $r->v;
        }

        return $acc;
    }

    /**
     * Merge a `ledgerByColumn` result with a name map into display rows.
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
            $receivable = (float) ($r->receivable ?? 0);
            $outstanding = (float) ($r->outstanding ?? 0);

            $rows[] = $identity($id, $name) + [
                'receivable' => $receivable,
                'collected' => (float) ($r->collected ?? 0),
                'outstanding' => $outstanding,
                'overdue' => (float) ($r->overdue ?? 0),
                'efficiency' => $receivable > 0.0 ? round(($receivable - $outstanding) / $receivable * 100, 1) : null,
            ];
        }

        usort($rows, fn ($a, $b) => $b['outstanding'] <=> $a['outstanding']);

        return $rows;
    }
}
