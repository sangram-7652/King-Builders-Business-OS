<?php

declare(strict_types=1);

namespace App\Support\Reports;

/**
 * The option lists that populate the global report filter component (M11.1).
 *
 * Every list is already access-scoped by the ReportFilterOptions service — a
 * blade must render exactly what it is handed and never widen it. `blocks` only
 * ever contains the blocks of the currently-selected project.
 *
 * All maps are `id|value => label`.
 */
final readonly class FilterOptions
{
    /**
     * @param  array<int, string>  $projects
     * @param  array<int, string>  $blocks
     * @param  array<int, string>  $salespeople
     * @param  array<string, string>  $datePresets
     * @param  array<string, string>  $bookingStatuses
     * @param  array<string, string>  $paymentStatuses
     * @param  array<string, string>  $plotStatuses
     */
    public function __construct(
        public array $projects = [],
        public array $blocks = [],
        public array $salespeople = [],
        public array $datePresets = [],
        public array $bookingStatuses = [],
        public array $paymentStatuses = [],
        public array $plotStatuses = [],
    ) {}
}
