<?php

declare(strict_types=1);

namespace App\Queries\Reports;

use App\Support\Reports\ReportFilterData;
use App\Support\Reports\ReportResult;
use Illuminate\Database\Eloquent\Builder;

/**
 * Base class for a single tabular report query (M11.1 foundation).
 *
 * M11.3–M11.6 extend this, add a `get(): ReportResult` that returns
 * SQL-aggregated rows (`selectRaw('… , count(*), sum(…)')` + `groupBy(…)`) and
 * reuse the protected `apply*` helpers, which all delegate to
 * {@see ReportFilterScope} so "how a filter maps to a column" lives in exactly
 * one place. Nothing here pulls whole datasets into PHP.
 *
 * Dashboard-style analytics (M11.2) that return richer shapes than a single
 * `ReportResult` call {@see ReportFilterScope} directly instead of extending
 * this class.
 *
 *   $this->applyDateRange($q, 'bookings.booking_date');
 *   $this->applyProject($q, 'bookings.project_id');
 *   $this->applySalesperson($q, 'bookings.created_by');
 */
abstract class ReportQuery
{
    public function __construct(protected readonly ReportFilterData $filters) {}

    abstract public function get(): ReportResult;

    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    protected function applyDateRange(Builder $query, string $column): Builder
    {
        return ReportFilterScope::dateRange($query, $this->filters, $column);
    }

    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    protected function applyProject(Builder $query, string $column = 'project_id'): Builder
    {
        return ReportFilterScope::project($query, $this->filters, $column);
    }

    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    protected function applyBlock(Builder $query, string $column = 'block_id'): Builder
    {
        return ReportFilterScope::block($query, $this->filters, $column);
    }

    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    protected function applySalesperson(Builder $query, string $column): Builder
    {
        return ReportFilterScope::salesperson($query, $this->filters, $column);
    }

    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    protected function applyBookingStatus(Builder $query, string $column = 'status'): Builder
    {
        return ReportFilterScope::bookingStatus($query, $this->filters, $column);
    }

    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    protected function applyPaymentStatus(Builder $query, string $column = 'status'): Builder
    {
        return ReportFilterScope::paymentStatus($query, $this->filters, $column);
    }

    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    protected function applyPlotStatus(Builder $query, string $column = 'status'): Builder
    {
        return ReportFilterScope::plotStatus($query, $this->filters, $column);
    }

    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    protected function applyLeadSource(Builder $query, string $column = 'lead_source_id'): Builder
    {
        return ReportFilterScope::leadSource($query, $this->filters, $column);
    }
}
