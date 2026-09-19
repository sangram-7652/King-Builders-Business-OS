<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\BuyerStatus;
use App\Enums\Permission;
use App\Models\Buyer;
use App\Models\User;
use App\Services\Reports\ExecutiveDashboardService;
use App\Support\Dashboard\DashboardViewData;
use App\Support\Reports\ReportFilterData;
use Throwable;

/**
 * Assembles the executive `/dashboard`.
 *
 * Orchestration only. The bulk of the page — every KPI, the sales / payments
 * / inventory overviews, the "attention required" operational counts and the
 * recent-bookings list — is the M11.2 {@see ExecutiveDashboardService}, reused
 * verbatim so a dashboard figure can never drift from the Reports › Overview
 * page. This service adds just one dashboard-only section: the active-buyer
 * snapshot count (no reporting service exposes it).
 *
 * Each added section is isolated: a failing query is reported, its slot is left
 * `null` and the rest of the dashboard still renders. Financial truth is never
 * touched here — it stays inside the M7/M8-backed reporting analytics.
 */
class DashboardService
{
    public function __construct(
        private readonly ExecutiveDashboardService $executive,
    ) {}

    public function build(ReportFilterData $filters, User $user, string $salesMetric = 'value'): DashboardViewData
    {
        /** @var list<string> $errors */
        $errors = [];
        $safe = function (string $section, callable $fn) use (&$errors) {
            try {
                return $fn();
            } catch (Throwable $e) {
                report($e);
                $errors[] = 'Could not load '.lcfirst($section).'.';

                return null;
            }
        };

        $exec = $this->executive->build($filters, $user, $salesMetric);

        $canBuyers = $user->can(Permission::BuyersView->value);
        $activeBuyers = $canBuyers
            ? $safe('Active buyers', fn (): int => Buyer::query()
                ->where('status', BuyerStatus::Active->value)
                ->count())
            : null;

        return new DashboardViewData(
            exec: $exec,
            activeBuyers: $activeBuyers,
            errors: $errors,
        );
    }
}
