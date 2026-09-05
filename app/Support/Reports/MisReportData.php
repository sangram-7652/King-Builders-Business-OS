<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Services\Reports\MisReportService;

/**
 * Everything the Management MIS screen needs (M11.5), assembled by
 * {@see MisReportService}.
 *
 * Each section is isolated — a failing query records an error string and that
 * section renders "unavailable", never a fake zero. Every financial figure is
 * M7/M8 truth applied as a SQL aggregate (no separate engine).
 */
final readonly class MisReportData
{
    /**
     * @param  list<Kpi>  $kpis
     * @param  list<array<string, int|float|string>>  $daily
     * @param  list<array<string, int|float|string|null>>  $monthly
     * @param  list<array<string, int|float|string|null>>  $projects
     * @param  list<array<string, int|float|string|null>>  $salespeople
     * @param  array<string, int|float|null>  $collectionSummary
     * @param  array<string, string>  $errors  section => message
     */
    public function __construct(
        public array $kpis,
        public array $daily,
        public bool $dailyTruncated,
        public array $monthly,
        public array $projects,
        public array $salespeople,
        public bool $salespeopleScoped,
        public array $collectionSummary,
        public ReportFilterData $filters,
        public string $generatedAt,
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
}
