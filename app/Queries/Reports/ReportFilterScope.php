<?php

declare(strict_types=1);

namespace App\Queries\Reports;

use App\Support\Reports\ReportFilterData;
use Illuminate\Contracts\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

/**
 * The one place a {@see ReportFilterData} becomes SQL `WHERE` clauses (M11.1
 * foundation, consumed from M11.2).
 *
 * Static + pure so it works against an Eloquent builder, a query builder or a
 * sub-query builder without any state. Every report query, KPI aggregate and
 * chart series routes its filtering through here, so "how a filter maps to a
 * column" is defined exactly once.
 *
 * Date comparisons use `whereDate()` on purpose: the window is already bounded
 * by the filter, report queries are not a hot path, and `whereDate()` is the
 * only form that is correct for both `date` and `datetime` columns on both
 * MySQL and SQLite.
 *
 * @phpstan-type AnyBuilder EloquentBuilder<*>|BaseBuilder
 */
final class ReportFilterScope
{
    /**
     * @template TBuilder of EloquentBuilder<*>|BaseBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function dateRange(EloquentBuilder|BaseBuilder $query, ReportFilterData $filters, string $column): EloquentBuilder|BaseBuilder
    {
        return $query
            ->whereDate($column, '>=', $filters->from->toDateString())
            ->whereDate($column, '<=', $filters->to->toDateString());
    }

    /**
     * @template TBuilder of EloquentBuilder<*>|BaseBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function project(EloquentBuilder|BaseBuilder $query, ReportFilterData $filters, string $column = 'project_id'): EloquentBuilder|BaseBuilder
    {
        return $filters->projectId === null
            ? $query
            : $query->where($column, $filters->projectId);
    }

    /**
     * @template TBuilder of EloquentBuilder<*>|BaseBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function block(EloquentBuilder|BaseBuilder $query, ReportFilterData $filters, string $column = 'block_id'): EloquentBuilder|BaseBuilder
    {
        return $filters->blockId === null
            ? $query
            : $query->where($column, $filters->blockId);
    }

    /**
     * @template TBuilder of EloquentBuilder<*>|BaseBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function salesperson(EloquentBuilder|BaseBuilder $query, ReportFilterData $filters, string $column): EloquentBuilder|BaseBuilder
    {
        return $filters->salespersonId === null
            ? $query
            : $query->where($column, $filters->salespersonId);
    }

    /**
     * @template TBuilder of EloquentBuilder<*>|BaseBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function bookingStatus(EloquentBuilder|BaseBuilder $query, ReportFilterData $filters, string $column = 'status'): EloquentBuilder|BaseBuilder
    {
        return $filters->bookingStatus === null
            ? $query
            : $query->where($column, $filters->bookingStatus->value);
    }

    /**
     * @template TBuilder of EloquentBuilder<*>|BaseBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function paymentStatus(EloquentBuilder|BaseBuilder $query, ReportFilterData $filters, string $column = 'status'): EloquentBuilder|BaseBuilder
    {
        return $filters->paymentStatus === null
            ? $query
            : $query->where($column, $filters->paymentStatus->value);
    }

    /**
     * @template TBuilder of EloquentBuilder<*>|BaseBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function plotStatus(EloquentBuilder|BaseBuilder $query, ReportFilterData $filters, string $column = 'status'): EloquentBuilder|BaseBuilder
    {
        return $filters->plotStatus === null
            ? $query
            : $query->where($column, $filters->plotStatus->value);
    }

    /**
     * @template TBuilder of EloquentBuilder<*>|BaseBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function leadSource(EloquentBuilder|BaseBuilder $query, ReportFilterData $filters, string $column = 'lead_source_id'): EloquentBuilder|BaseBuilder
    {
        return $filters->leadSourceId === null
            ? $query
            : $query->where($column, $filters->leadSourceId);
    }

    /**
     * Exclude soft-deleted rows from a raw report query. The operational
     * modules use Eloquent and never see soft-deleted records — a raw report
     * query must apply the same `deleted_at IS NULL` filter or it double-counts
     * (F-REP-2). Pass every qualified table name / alias that carries a
     * `deleted_at` column.
     *
     * @template TBuilder of EloquentBuilder<*>|BaseBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function notDeleted(EloquentBuilder|BaseBuilder $query, string ...$tables): EloquentBuilder|BaseBuilder
    {
        foreach ($tables as $table) {
            $query->whereNull("{$table}.deleted_at");
        }

        return $query;
    }
}
