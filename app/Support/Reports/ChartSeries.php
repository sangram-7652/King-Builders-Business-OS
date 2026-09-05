<?php

declare(strict_types=1);

namespace App\Support\Reports;

/**
 * A simple time-bucketed series for the dashboard's pure-CSS bar chart (M11.2).
 *
 * `points` is an ordered list of `{ label, key, values: [metric => float] }`.
 * The chart component is metric-agnostic — it renders whichever metric it is
 * told to.
 */
final readonly class ChartSeries
{
    /**
     * @param  list<array{label: string, key: string, values: array<string, float>}>  $points
     * @param  list<string>  $metrics
     */
    public function __construct(
        public array $points,
        public string $granularity,   // day | week | month
        public array $metrics = [],
        public ?string $error = null,
    ) {}

    public static function failed(string $message): self
    {
        return new self(points: [], granularity: 'day', error: $message);
    }

    /** No buckets at all, or every bucket is zero across every metric. */
    public function isEmpty(): bool
    {
        if ($this->points === []) {
            return true;
        }

        foreach ($this->points as $point) {
            foreach ($point['values'] as $value) {
                if ((float) $value !== 0.0) {
                    return false;
                }
            }
        }

        return true;
    }

    public function max(string $metric): float
    {
        $values = array_map(fn (array $p) => $p['values'][$metric] ?? 0.0, $this->points);

        return $values === [] ? 0.0 : (float) max($values);
    }

    public function total(string $metric): float
    {
        return array_sum(array_map(fn (array $p) => $p['values'][$metric] ?? 0.0, $this->points));
    }
}
