<?php

declare(strict_types=1);

namespace App\Support\Reports;

/**
 * The generic shape every report returns (M11.1 foundation).
 *
 * `rows` are already-aggregated result rows (never hydrated domain models),
 * `summary` holds scalar totals for the header cards, and `meta` carries
 * generation info. M11.2–M11.6 build these from SQL `COUNT` / `SUM` / `GROUP BY`
 * — this class deliberately holds no query logic.
 *
 * @phpstan-type Row array<string, scalar|null>
 */
final readonly class ReportResult
{
    /**
     * @param  list<array<string, scalar|null>>  $rows
     * @param  array<string, scalar|null>  $summary
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public array $rows = [],
        public array $summary = [],
        public array $meta = [],
    ) {}

    public static function empty(): self
    {
        return new self(meta: ['generated_at' => now()->toIso8601String(), 'placeholder' => true]);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function count(): int
    {
        return count($this->rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rows' => $this->rows,
            'summary' => $this->summary,
            'meta' => $this->meta,
        ];
    }
}
