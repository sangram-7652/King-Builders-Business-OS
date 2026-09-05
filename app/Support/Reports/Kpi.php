<?php

declare(strict_types=1);

namespace App\Support\Reports;

/**
 * One dashboard KPI (M11.2).
 *
 * Formats: `number` (plain count), `currency` (₹, 0 dp), `percent` (0–100, 1 dp).
 *
 * A `null` value means "not computed" — either the section is not applicable or
 * the query failed (`error` is set). The blade must render that as an em dash,
 * never as `0`.
 *
 * `previous` is only set for period-flow KPIs that support a like-for-like
 * comparison; snapshot KPIs (plot counts, current outstanding, …) leave it null.
 */
final readonly class Kpi
{
    public function __construct(
        public string $key,
        public string $label,
        public int|float|null $value,
        public string $format = 'number',
        public int|float|null $previous = null,
        public ?string $error = null,
        public ?string $hint = null,
    ) {}

    public function failed(): bool
    {
        return $this->error !== null;
    }

    public function hasComparison(): bool
    {
        return $this->previous !== null && $this->value !== null && ! $this->failed();
    }

    /**
     * Percentage change vs the previous period, or null when it cannot be
     * expressed without dividing by zero. Never returns INF / NAN.
     *
     * For a `percent` KPI this is the difference in *points*, not a ratio.
     */
    public function delta(): ?float
    {
        if (! $this->hasComparison()) {
            return null;
        }

        if ($this->format === 'percent') {
            return round((float) $this->value - (float) $this->previous, 1);
        }

        if ((float) $this->previous === 0.0) {
            return null; // no meaningful % change from a zero baseline
        }

        return round((((float) $this->value - (float) $this->previous) / abs((float) $this->previous)) * 100, 1);
    }

    /** 'up' | 'down' | 'flat' | null */
    public function direction(): ?string
    {
        $delta = $this->delta();

        if ($delta === null) {
            return null;
        }

        return match (true) {
            $delta > 0 => 'up',
            $delta < 0 => 'down',
            default => 'flat',
        };
    }

    public function deltaUnit(): string
    {
        return $this->format === 'percent' ? 'pts' : '%';
    }
}
