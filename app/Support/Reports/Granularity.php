<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Services\Reports\SalesAnalytics;
use Carbon\CarbonImmutable;

/**
 * Shared time-bucketing for report trend charts (M11.4).
 *
 * Picks a sensible daily / weekly / monthly granularity from the window width
 * and produces the ordered, gap-filled list of buckets that a SQL daily
 * aggregate is folded into. Identical semantics to the private helper inside
 * {@see SalesAnalytics} — extracted here so M11.4+ report
 * services reuse one implementation without touching M11.3 code.
 */
final class Granularity
{
    public const DAY = 'day';

    public const WEEK = 'week';

    public const MONTH = 'month';

    /** day (≤ 45d) · week (≤ 183d) · month (otherwise). */
    public static function for(ReportFilterData $filters): string
    {
        $days = $filters->from->startOfDay()->diffInDays($filters->to->startOfDay()) + 1;

        return match (true) {
            $days <= 45 => self::DAY,
            $days <= 183 => self::WEEK,
            default => self::MONTH,
        };
    }

    /**
     * The bucket key a given date rolls into.
     */
    public static function keyFor(CarbonImmutable $date, string $granularity): string
    {
        return match ($granularity) {
            self::WEEK => $date->startOfWeek()->toDateString(),
            self::MONTH => $date->format('Y-m'),
            default => $date->toDateString(),
        };
    }

    /**
     * Ordered, gap-filled buckets spanning the filter window.
     *
     * @return list<array{0: string, 1: string}> [key, label]
     */
    public static function buckets(ReportFilterData $filters, string $granularity): array
    {
        $cursor = match ($granularity) {
            self::WEEK => $filters->from->startOfWeek(),
            self::MONTH => $filters->from->startOfMonth(),
            default => $filters->from->startOfDay(),
        };
        $end = $filters->to->startOfDay();
        $buckets = [];

        while ($cursor->lessThanOrEqualTo($end)) {
            [$next, $label] = match ($granularity) {
                self::WEEK => [$cursor->addWeek(), 'w/c '.$cursor->format('d M')],
                self::MONTH => [$cursor->addMonthNoOverflow(), $cursor->format('M Y')],
                default => [$cursor->addDay(), $cursor->format('d M')],
            };
            $buckets[] = [self::keyFor($cursor, $granularity), $label];
            $cursor = $next;
        }

        return $buckets;
    }

    /**
     * Every calendar month the window touches, oldest first.
     *
     * @return list<array{0: string, 1: string}> ['YYYY-MM', 'Mon YYYY']
     */
    public static function months(ReportFilterData $filters): array
    {
        $cursor = $filters->from->startOfMonth();
        $end = $filters->to->startOfMonth();
        $out = [];

        while ($cursor->lessThanOrEqualTo($end)) {
            $out[] = [$cursor->format('Y-m'), $cursor->format('M Y')];
            $cursor = $cursor->addMonthNoOverflow();
        }

        return $out;
    }
}
