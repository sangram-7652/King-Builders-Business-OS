<?php

declare(strict_types=1);

namespace App\Support\Reports;

/**
 * A single named, already-aggregated table inside a report export (M11.5).
 *
 * `rows` are plain associative arrays keyed by column key (never hydrated
 * models — every row is a SQL aggregate produced by the report's analytics
 * service). `totals`, when present, is one extra row keyed the same way.
 *
 * This is the shared contract: the MIS screen renders `ReportTable`s and every
 * exporter (CSV / XLSX / PDF / print) serialises the very same objects, so an
 * exported figure can never drift from what the screen shows.
 *
 * @phpstan-type Row array<string, scalar|null>
 */
final readonly class ReportTable
{
    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<array<string, scalar|null>>  $rows
     * @param  array<string, scalar|null>|null  $totals
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $columns,
        public array $rows,
        public ?array $totals = null,
        public ?string $note = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function count(): int
    {
        return count($this->rows);
    }

    /**
     * The header labels in column order.
     *
     * @return list<string>
     */
    public function headings(): array
    {
        return array_map(fn (ReportColumn $c) => $c->label, $this->columns);
    }

    /**
     * A row as an ordered list of raw cell values (spreadsheet-friendly:
     * numbers stay numbers).
     *
     * @param  array<string, scalar|null>  $row
     * @return list<scalar|null>
     */
    public function rawCells(array $row): array
    {
        return array_map(function (ReportColumn $c) use ($row) {
            $value = $row[$c->key] ?? null;

            if ($value !== null && $c->isNumeric()) {
                return $c->format === 'number' ? (int) round((float) $value) : round((float) $value, 2);
            }

            return $value;
        }, $this->columns);
    }

    /**
     * A row as an ordered list of display strings (CSV / PDF / print).
     *
     * @param  array<string, scalar|null>  $row
     * @return list<string>
     */
    public function displayCells(array $row): array
    {
        return array_map(fn (ReportColumn $c) => $c->display($row[$c->key] ?? null), $this->columns);
    }
}
