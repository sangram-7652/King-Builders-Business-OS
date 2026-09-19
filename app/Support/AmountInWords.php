<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Renders a rupee amount as Indian-English words for printed documents
 * (e.g. receipts) — "Three Thousand" for 3000.00. Uses the Indian numbering
 * system (lakh / crore), not the Western thousand/million grouping.
 */
final class AmountInWords
{
    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private const TENS = [
        '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety',
    ];

    public static function rupees(string $amount): string
    {
        $rupees = (int) bcdiv($amount, '1', 0);
        $paise = (int) bcmul(bcsub($amount, (string) $rupees, 6), '100', 0);

        $words = self::number($rupees).' Rupee'.($rupees === 1 ? '' : 's');

        if ($paise > 0) {
            $words .= ' and '.self::number($paise).' Paise';
        }

        return $words.' Only';
    }

    private static function number(int $value): string
    {
        if ($value === 0) {
            return 'Zero';
        }

        $parts = [];

        $crore = intdiv($value, 10000000);
        $value %= 10000000;
        $lakh = intdiv($value, 100000);
        $value %= 100000;
        $thousand = intdiv($value, 1000);
        $value %= 1000;
        $hundred = intdiv($value, 100);
        $rest = $value % 100;

        if ($crore > 0) {
            $parts[] = self::twoDigit($crore).' Crore';
        }
        if ($lakh > 0) {
            $parts[] = self::twoDigit($lakh).' Lakh';
        }
        if ($thousand > 0) {
            $parts[] = self::twoDigit($thousand).' Thousand';
        }
        if ($hundred > 0) {
            $parts[] = self::ONES[$hundred].' Hundred';
        }
        if ($rest > 0) {
            $parts[] = self::twoDigit($rest);
        }

        return implode(' ', $parts);
    }

    private static function twoDigit(int $value): string
    {
        if ($value < 20) {
            return self::ONES[$value];
        }

        $tens = self::TENS[intdiv($value, 10)];
        $ones = self::ONES[$value % 10];

        return trim("{$tens} {$ones}");
    }
}
