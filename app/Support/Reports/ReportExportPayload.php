<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\ReportType;
use App\Services\Reports\ReportExportBuilder;
use Carbon\CarbonImmutable;

/**
 * The complete, self-contained description of one report export (M11.5).
 *
 * Built once by {@see ReportExportBuilder} from the same
 * analytics services the screen uses, then handed to whichever exporter the
 * user picked. Holds no query logic and no models — just titled tables, the
 * human-readable filter summary and generation metadata.
 */
final readonly class ReportExportPayload
{
    /**
     * @param  list<ReportTable>  $tables
     * @param  array<string, string>  $filterSummary  label => value (applied filters)
     */
    public function __construct(
        public ReportType $type,
        public string $title,
        public string $periodLabel,
        public array $tables,
        public array $filterSummary,
        public CarbonImmutable $generatedAt,
        public string $generatedBy,
    ) {}

    /** A filesystem-safe base name, e.g. `mis-report_2026-09-02_1530`. */
    public function filename(): string
    {
        return $this->type->value.'-report_'.$this->generatedAt->format('Y-m-d_Hi');
    }

    public function generatedAtLabel(): string
    {
        return $this->generatedAt->format('d M Y, H:i');
    }

    public function isEmpty(): bool
    {
        foreach ($this->tables as $table) {
            if (! $table->isEmpty()) {
                return false;
            }
        }

        return true;
    }
}
