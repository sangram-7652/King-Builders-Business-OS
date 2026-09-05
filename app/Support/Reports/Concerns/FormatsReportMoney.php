<?php

declare(strict_types=1);

namespace App\Support\Reports\Concerns;

use App\Support\Money;

/**
 * F-REP-1 — every money value that leaves an analytics service passes through
 * bcmath here first.
 *
 * SQL `SUM()` on a `DECIMAL(15,2)` column already returns an exact decimal
 * string; this canonicalises it to the 2-decimal value a `DECIMAL(15,2)` column
 * would hold (HALF_UP) before it is exposed, so a report figure can never drift
 * from the operational ledger by a fraction of a paisa and CSV / PDF / on-screen
 * can never disagree. Aggregation itself stays in SQL as DECIMAL — this never
 * does arithmetic on floats.
 */
trait FormatsReportMoney
{
    /** Canonical 2-decimal string (for exact comparison / export). */
    protected function reportMoneyString(mixed $value): string
    {
        return Money::of((string) ($value ?? '0'))->store();
    }

    /**
     * Canonical value as a float — exact for any realistic portfolio total
     * (< ~₹9e13), kept for the existing float-typed report DTOs.
     */
    protected function reportMoney(mixed $value): float
    {
        return (float) $this->reportMoneyString($value);
    }

    /** bcmath subtraction of two money-ish values, canonicalised. */
    protected function reportMoneyMinus(mixed $a, mixed $b): float
    {
        return (float) Money::of((string) ($a ?? '0'))
            ->minus(Money::of((string) ($b ?? '0')))
            ->store();
    }
}
