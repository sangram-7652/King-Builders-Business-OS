<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Aging buckets for OVERDUE receivables (M8). Computed purely from
 * `days overdue = today − installment due date`. A not-yet-overdue installment
 * has no bucket; a paid / waived installment never appears in aging.
 */
enum AgingBucket: string
{
    use HasLabel;

    case Days0To30 = '0-30';
    case Days31To60 = '31-60';
    case Days61To90 = '61-90';
    case Days91To180 = '91-180';
    case Days180Plus = '180+';

    public function label(): string
    {
        return match ($this) {
            self::Days0To30 => '0–30 days',
            self::Days31To60 => '31–60 days',
            self::Days61To90 => '61–90 days',
            self::Days91To180 => '91–180 days',
            self::Days180Plus => '180+ days',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Days0To30 => 'warning',
            self::Days31To60 => 'warning',
            self::Days61To90 => 'danger',
            self::Days91To180 => 'danger',
            self::Days180Plus => 'danger',
        };
    }

    /**
     * The single source of truth for the aging-bucket day boundaries
     * (F-M8-1). Inclusive `[minDays, maxDays]` per bucket; a null `maxDays` is
     * open-ended. Both the operational path ({@see self::fromDaysOverdue},
     * consumed by App\Services\Collections\AgingCalculator) and the reporting
     * path (App\Services\Reports\CollectionAnalytics) derive their windows from
     * here — the numbers 30 / 60 / 90 / 180 live in exactly one place.
     *
     * @return array<value-of<self>, array{0: int, 1: int|null}>
     */
    public static function dayBounds(): array
    {
        return [
            self::Days0To30->value => [1, 30],
            self::Days31To60->value => [31, 60],
            self::Days61To90->value => [61, 90],
            self::Days91To180->value => [91, 180],
            self::Days180Plus->value => [181, null],
        ];
    }

    /** @return array{0: int, 1: int|null} inclusive [minDays, maxDays] for this bucket */
    public function bounds(): array
    {
        return self::dayBounds()[$this->value];
    }

    /**
     * The upper day thresholds that separate the buckets: [30, 60, 90, 180].
     *
     * @return list<int>
     */
    public static function thresholds(): array
    {
        return array_values(array_filter(
            array_map(static fn (array $b): ?int => $b[1], self::dayBounds()),
            static fn (?int $v): bool => $v !== null,
        ));
    }

    /**
     * Bucket for a given number of days overdue. Returns null when the item is
     * not overdue (days <= 0). Derived from {@see self::dayBounds()}.
     */
    public static function fromDaysOverdue(int $days): ?self
    {
        if ($days <= 0) {
            return null;
        }

        foreach (self::dayBounds() as $value => [$min, $max]) {
            if ($max === null || $days <= $max) {
                return self::from($value);
            }
        }

        return self::Days180Plus;
    }

    /** @return array<value-of<self>, string> value => label, in display order */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
