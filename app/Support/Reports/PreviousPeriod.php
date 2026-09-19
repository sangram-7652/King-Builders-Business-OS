<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\DatePreset;
use Carbon\CarbonImmutable;

/**
 * Derives the "previous equivalent period" for a set of report filters (M11.2).
 *
 * A named preset maps to its natural predecessor (this month → last month,
 * this quarter → the quarter before, …). A custom range of N days maps to the N
 * days immediately before it. Every other filter (project, salesperson, …) is
 * carried across unchanged so the comparison is like-for-like.
 */
final class PreviousPeriod
{
    public static function for(ReportFilterData $current): ReportFilterData
    {
        [$from, $to] = self::window($current);

        return new ReportFilterData(
            from: $from->startOfDay(),
            to: $to->endOfDay(),
            preset: DatePreset::Custom,
            projectId: $current->projectId,
            blockId: $current->blockId,
            salespersonId: $current->salespersonId,
            bookingStatus: $current->bookingStatus,
            paymentStatus: $current->paymentStatus,
            plotStatus: $current->plotStatus,
        );
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function window(ReportFilterData $current): array
    {
        $from = $current->from;
        $to = $current->to;

        return match ($current->preset) {
            DatePreset::Today, DatePreset::Yesterday => [$from->subDay(), $from->subDay()],
            DatePreset::ThisWeek => [$from->subWeek(), $to->subWeek()],
            DatePreset::ThisMonth, DatePreset::LastMonth => [
                $from->subMonthNoOverflow()->startOfMonth(),
                $from->subMonthNoOverflow()->endOfMonth(),
            ],
            DatePreset::ThisQuarter => [
                $from->subQuarterNoOverflow()->startOfQuarter(),
                $from->subQuarterNoOverflow()->endOfQuarter(),
            ],
            DatePreset::ThisYear => [
                $from->subYearNoOverflow()->startOfYear(),
                $from->subYearNoOverflow()->endOfYear(),
            ],
            // Custom (or a bare from/to): shift back by the exact span.
            DatePreset::Custom => self::shiftBack($from, $to),
        };
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function shiftBack(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = $from->startOfDay()->diffInDays($to->startOfDay()) + 1;

        return [$from->subDays($days), $to->subDays($days)];
    }
}
