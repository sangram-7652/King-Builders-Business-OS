<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\AgingBucket;
use App\Enums\BookingStatus;
use App\Enums\DatePreset;
use App\Enums\PaymentStatus;
use App\Enums\PlotStatus;
use App\Enums\UserStatus;
use App\Models\Block;
use App\Models\Masters\LeadSource;
use App\Models\Masters\PaymentMode;
use App\Models\Project;
use App\Models\User;
use App\Support\Reports\FilterOptions;

/**
 * Builds the access-scoped option lists for the global report filter (M11.1).
 *
 * Scoping rules (reuse of the existing RBAC / `leads.view_all` convention):
 *  - projects: every active project (single-tenant install)
 *  - blocks: only the active blocks of the currently-selected project
 *  - salespeople: every active user for a `leads.view_all` holder; otherwise
 *    just the requesting user (a scoped user cannot pick anyone else)
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
            salespeople: $this->salespeople($user),
            leadSources: LeadSource::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all(),
            paymentModes: PaymentMode::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all(),
            datePresets: DatePreset::options(),
            bookingStatuses: BookingStatus::options(),
            paymentStatuses: PaymentStatus::options(),
            plotStatuses: PlotStatus::options(),
            ageingBuckets: AgingBucket::options(),
        );
    }

    /**
     * @return array<int, string>
     */
    private function salespeople(User $user): array
    {
        if (! $user->can('leads.view_all')) {
            return [$user->getKey() => $user->name];
        }

        return User::query()
            ->where('status', UserStatus::Active->value)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
