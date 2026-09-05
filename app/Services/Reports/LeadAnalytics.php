<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Queries\Reports\ReportFilterScope;
use App\Support\Reports\ReportFilterData;
use Illuminate\Database\Eloquent\Builder;

/**
 * SQL-aggregated lead analytics for the executive dashboard (M11.2).
 *
 * Leads are not project-scoped in M5, so the project / block filters do not
 * apply here — only the salesperson (`assigned_to`), lead source and date
 * window (`created_at`) do. Conversion is cohort-based: of the leads *created*
 * in the window, how many now sit in the CONVERTED state.
 */
class LeadAnalytics
{
    /**
     * @return array{total: int, converted: int, conversionPercent: float|null}
     */
    public function summary(ReportFilterData $filters): array
    {
        $row = $this->scoped($filters)
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as converted', [LeadStatus::Converted->value])
            ->first();

        $total = (int) ($row->total ?? 0);
        $converted = (int) ($row->converted ?? 0);

        return [
            'total' => $total,
            'converted' => $converted,
            'conversionPercent' => $total > 0 ? round($converted / $total * 100, 1) : null,
        ];
    }

    /**
     * Pipeline stage breakdown for the executive dashboard's funnel — one
     * SQL-aggregated count per {@see LeadStatus}, over the same cohort as
     * {@see summary()} (leads *created* in the window, honouring the
     * salesperson / lead-source filters). A stage with no leads is omitted.
     *
     * @return array<string, int>  LeadStatus value => count
     */
    public function pipeline(ReportFilterData $filters): array
    {
        return $this->scoped($filters)
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                ($row->status instanceof LeadStatus ? $row->status->value : (string) $row->status) => (int) $row->aggregate,
            ])
            ->all();
    }

    /**
     * @return Builder<Lead>
     */
    private function scoped(ReportFilterData $filters)
    {
        $query = Lead::query();
        ReportFilterScope::dateRange($query, $filters, 'created_at');
        ReportFilterScope::salesperson($query, $filters, 'assigned_to');
        ReportFilterScope::leadSource($query, $filters);

        return $query;
    }
}
