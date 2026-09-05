<?php

declare(strict_types=1);

namespace App\Support\Reports;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Everything the collections report blade needs (M11.4), assembled by
 * `App\Services\Reports\CollectionReportService`.
 *
 * Each section is isolated: a failing analytics query records an error string
 * and that slot renders "unavailable" — never a fake zero. Every figure is M7
 * (`PaymentLedger`) / M8 (`AgingCalculator`) truth, applied as SQL aggregates.
 *
 * @phpstan-type AgeRow array{bucket:string, label:string, outstanding:float, installments:int, customers:int}
 * @phpstan-type RollupRow array{receivable:float, collected:float, outstanding:float, overdue:float, efficiency:float|null}
 */
final readonly class CollectionReportData
{
    /**
     * @param  list<Kpi>  $kpis
     * @param  list<array<string, mixed>>  $ageing
     * @param  list<array<string, mixed>>  $projectCollection
     * @param  list<array<string, mixed>>  $blockCollection
     * @param  list<array<string, mixed>>  $salespersonCollection
     * @param  array{outstanding: list<array<string,mixed>>, overdue: list<array<string,mixed>>}  $topCustomers
     * @param  list<array<string, mixed>>  $paymentMethods
     * @param  array{received:int, cleared:int, pending:int, bounced:int, bounced_amount:float, bank_charges:float}  $cheques
     * @param  list<array<string, mixed>>  $monthly
     * @param  array{next7:float, next30:float}  $expected
     * @param  array{bookingValue:float, receivable:float, cashCollected:float, outstanding:float, unallocated:float}  $reconciliation
     * @param  LengthAwarePaginator<int, object>  $recovery
     * @param  array<string, string>  $errors
     */
    public function __construct(
        public array $kpis,
        public ChartSeries $trend,
        public ChartSeries $efficiencyTrend,
        public array $ageing,
        public array $projectCollection,
        public array $blockCollection,
        public array $salespersonCollection,
        public bool $salespeopleScoped,
        public array $topCustomers,
        public array $paymentMethods,
        public array $cheques,
        public array $monthly,
        public array $expected,
        public array $reconciliation,
        public LengthAwarePaginator $recovery,
        public string $recoverySort,
        public ReportFilterData $filters,
        public CollectionFilters $extras,
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

    public function error(string $section): ?string
    {
        return $this->errors[$section] ?? null;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * The two "critical" ageing rows (91–180 and 180+), most severe first.
     *
     * @return list<array<string, mixed>>
     */
    public function criticalAgeing(): array
    {
        $severity = ['180+' => 0, '91-180' => 1];

        return collect($this->ageing)
            ->whereIn('bucket', array_keys($severity))
            ->sortBy(fn (array $row) => $severity[$row['bucket']])
            ->values()
            ->all();
    }
}
