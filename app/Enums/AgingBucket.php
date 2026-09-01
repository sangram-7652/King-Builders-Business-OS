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
     * Bucket for a given number of days overdue. Returns null when the item is
     * not overdue (days <= 0).
     */
    public static function fromDaysOverdue(int $days): ?self
    {
        return match (true) {
            $days <= 0 => null,
            $days <= 30 => self::Days0To30,
            $days <= 60 => self::Days31To60,
            $days <= 90 => self::Days61To90,
            $days <= 180 => self::Days91To180,
            default => self::Days180Plus,
        };
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
