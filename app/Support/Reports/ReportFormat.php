<?php

declare(strict_types=1);

namespace App\Support\Reports;

/**
 * Display formatting for dashboard figures (M11.2). Presentation only — never
 * used in a calculation.
 *
 * A `null` input always renders as an em dash ("—"): a failed / not-applicable
 * metric must never look like a real zero.
 */
final class ReportFormat
{
    public const DASH = '—';

    public static function number(int|float|null $value): string
    {
        return $value === null ? self::DASH : self::grouped((int) round($value));
    }

    /** Compact ₹ for cards: ₹4.5 Cr / ₹12.3 L / ₹8,500. */
    public static function currency(int|float|null $value): string
    {
        if ($value === null) {
            return self::DASH;
        }

        $abs = abs((float) $value);
        $sign = $value < 0 ? '-' : '';

        return match (true) {
            $abs >= 1_00_00_000 => $sign.'₹'.self::trim($abs / 1_00_00_000).' Cr',
            $abs >= 1_00_000 => $sign.'₹'.self::trim($abs / 1_00_000).' L',
            default => $sign.'₹'.self::grouped((int) round($abs)),
        };
    }

    /** Full ₹ with 2 dp, for tables. */
    public static function currencyFull(int|float|null $value): string
    {
        return $value === null ? self::DASH : '₹'.number_format((float) $value, 2);
    }

    public static function percent(int|float|null $value): string
    {
        return $value === null ? self::DASH : rtrim(rtrim(number_format((float) $value, 1), '0'), '.').'%';
    }

    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }

    /** Indian digit grouping (2,50,00,000). */
    private static function grouped(int $value): string
    {
        $negative = $value < 0;
        $whole = (string) abs($value);

        $last3 = substr($whole, -3);
        $rest = substr($whole, 0, -3);

        if ($rest !== '') {
            $rest = (string) preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $last3 = $rest.','.$last3;
        }

        return ($negative ? '-' : '').$last3;
    }
}
