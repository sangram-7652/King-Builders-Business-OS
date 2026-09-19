<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\BookingStatus;
use App\Enums\DatePreset;
use App\Enums\PaymentStatus;
use App\Enums\PlotStatus;
use App\Enums\UserStatus;
use App\Models\Block;
use App\Models\Project;
use App\Models\User;
use App\Support\Reports\FilterOptions;

/**
 * Builds the access-scoped option lists for the global report filter (M11.1).
 *
 * Scoping rules:
 *  - projects: every active project (single-tenant install)
 *  - blocks: only the active blocks of the currently-selected project
 *  - salespeople: every active user (any user who can reach a report page
 *    can filter/report on any salesperson)
 *
 * A multi-tenant build would narrow `projects` / `salespeople` here; every
 * report inherits the result, so there is one place to change.
 */
class ReportFilterOptions
{
    public function for(User $user, ?int $projectId = null): FilterOptions
    {
        return new FilterOptions(
            projects: Project::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all(),
            blocks: $projectId === null ? [] : Block::query()
                ->where('project_id', $projectId)
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all(),
            salespeople: $this->salespeople(),
            datePresets: DatePreset::options(),
            bookingStatuses: BookingStatus::options(),
            paymentStatuses: PaymentStatus::options(),
            plotStatuses: PlotStatus::options(),
        );
    }

    /**
     * @return array<int, string>
     */
    private function salespeople(): array
    {
        return User::query()
            ->where('status', UserStatus::Active->value)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
