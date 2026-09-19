<?php

declare(strict_types=1);

namespace App\Support\Dashboard;

use App\Support\Reports\ExecutiveDashboardData;

/**
 * Everything the executive `/dashboard` blade needs.
 *
 * The cross-module KPIs, sales / payments / inventory overviews, "attention
 * required" operational counts and recent-bookings list are delegated wholesale
 * to {@see ExecutiveDashboardData} (the M11.2 reporting truth — no figure is
 * recomputed here). This object only adds the one dashboard-only piece the
 * reporting suite does not expose: the active-buyer count.
 *
 * Every added figure is nullable: a failed query records an error and leaves
 * the slot `null`, which the blade must render as an em dash — never a zero.
 */
final readonly class DashboardViewData
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public ExecutiveDashboardData $exec,
        public ?int $activeBuyers,
        public array $errors = [],
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [] || $this->exec->hasErrors();
    }

    /**
     * All error strings — dashboard-only plus the delegated reporting ones —
     * de-duplicated for a single banner.
     *
     * @return list<string>
     */
    public function allErrors(): array
    {
        return array_values(array_unique([...$this->exec->errors, ...$this->errors]));
    }
}
