<?php

declare(strict_types=1);

namespace App\Support\Dashboard;

use App\Support\Reports\ExecutiveDashboardData;

/**
 * Everything the executive `/dashboard` blade needs.
 *
 * The cross-module KPIs, sales / collection / inventory overviews, "attention
 * required" operational counts and recent-bookings list are delegated wholesale
 * to {@see ExecutiveDashboardData} (the M11.2 reporting truth — no figure is
 * recomputed here). This object only adds the handful of dashboard-only pieces
 * the reporting suite does not expose: the active-buyer count, the M13 lead
 * pipeline funnel and the follow-up work-queue counts.
 *
 * Every added figure is nullable: a failed query records an error and leaves
 * the slot `null`, which the blade must render as an em dash — never a zero.
 *
 * @phpstan-type FollowUpCounts array{due_today: int|null, overdue: int|null, missed: int|null}
 */
final readonly class DashboardViewData
{
    /**
     * @param  array<string, int>  $leadPipeline  LeadStatus value => count (only non-empty stages)
     * @param  FollowUpCounts  $followUps
     * @param  list<string>  $errors
     */
    public function __construct(
        public ExecutiveDashboardData $exec,
        public ?int $activeBuyers,
        public array $leadPipeline,
        public bool $leadPipelineVisible,
        public array $followUps,
        public bool $followUpsVisible,
        public array $errors = [],
    ) {}

    public function followUp(string $key): ?int
    {
        return $this->followUps[$key] ?? null;
    }

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
