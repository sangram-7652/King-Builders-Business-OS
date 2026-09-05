<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;
use Carbon\CarbonImmutable;

/**
 * Global reporting date presets (M11.1).
 *
 * A preset resolves to an inclusive `[from, to]` window normalised to the start
 * and end of a day in the application timezone (`config('app.timezone')`), so
 * every report shares the same boundary semantics and there are no
 * off-by-a-day bugs. `Custom` defers to explicit `from` / `to` inputs.
 *
 * The default across the reporting suite is {@see self::ThisMonth}.
 */
enum DatePreset: string
{
    use HasLabel;

    case Today = 'today';
    case Yesterday = 'yesterday';
    case ThisWeek = 'this_week';
    case ThisMonth = 'this_month';
    case LastMonth = 'last_month';
    case ThisQuarter = 'this_quarter';
    case ThisYear = 'this_year';
    case Custom = 'custom';

    public const DEFAULT = self::ThisMonth;

    public function label(): string
    {
        return match ($this) {
            self::Today => 'Today',
            self::Yesterday => 'Yesterday',
            self::ThisWeek => 'This week',
            self::ThisMonth => 'This month',
            self::LastMonth => 'Last month',
            self::ThisQuarter => 'This quarter',
            self::ThisYear => 'This year',
            self::Custom => 'Custom range',
        };
    }

    /** Presets whose window is derived and cannot be hand-edited. */
    public function isFixed(): bool
    {
        return $this !== self::Custom;
    }

    /**
     * Resolve this preset to an inclusive day-aligned window.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} [from (start of day), to (end of day)]
     */
    public function resolveRange(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now(config('app.timezone'));
        $today = $now->startOfDay();

        [$from, $to] = match ($this) {
            self::Today => [$today, $today],
            self::Yesterday => [$today->subDay(), $today->subDay()],
            self::ThisWeek => [$today->startOfWeek(), $today->endOfWeek()->startOfDay()],
            self::ThisMonth => [$today->startOfMonth(), $today->endOfMonth()->startOfDay()],
            self::LastMonth => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            self::ThisQuarter => [$today->startOfQuarter(), $today->endOfQuarter()->startOfDay()],
            self::ThisYear => [$today->startOfYear(), $today->endOfYear()->startOfDay()],
            self::Custom => [$today->startOfMonth(), $today], // sensible fallback; real bounds come from inputs
        };

        return [$from->startOfDay(), $to->endOfDay()];
    }
}
