<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Exceptions\DomainException;
use App\Support\Money;
use App\Support\Payments\InstallmentSpec;
use Illuminate\Support\Carbon;

/**
 * Turns a plan total + a schedule into a list of {@see InstallmentSpec}s whose
 * amounts reconcile EXACTLY with the total (M7).
 *
 * Two schedule shapes:
 *   - percentage: each row is a % of the total; the rows must sum to exactly 100.
 *   - amount:     each row is a fixed ₹ figure; the rows must sum to exactly the total.
 *
 * Rounding is deterministic: every row except the last is rounded to 2 dp, and
 * the LAST installment absorbs whatever remains — so Σ installments == total,
 * always, with no negative row.
 */
class PaymentPlanGenerator
{
    /**
     * @param  list<array{type: 'percentage'|'amount', value: string|int|float, due_date: string, name?: string|null}>  $rows
     * @return list<InstallmentSpec>
     */
    public function generate(Money $total, array $rows): array
    {
        if (count($rows) < 1) {
            throw new DomainException('A payment plan needs at least one installment.');
        }

        if ($total->isNegative() || $total->isZero()) {
            throw new DomainException('A payment plan total must be greater than zero.');
        }

        $type = $rows[0]['type'];

        foreach ($rows as $row) {
            if (($row['type'] ?? null) !== $type) {
                throw new DomainException('A payment plan cannot mix percentage and fixed-amount installments.');
            }
        }

        $type === 'percentage'
            ? $this->assertPercentagesSumTo100($rows)
            : $this->assertAmountsSumTo($rows, $total);

        $specs = [];
        $allocated = Money::zero();
        $count = count($rows);

        foreach ($rows as $index => $row) {
            $isLast = $index === $count - 1;

            if ($isLast) {
                $amount = $total->minus($allocated);
            } elseif ($type === 'percentage') {
                $amount = Money::of((string) $row['value'])->percentageOf($total);
                $amount = Money::of($amount->store()); // fix at 2 dp for a clean running sum
            } else {
                $amount = Money::of((string) $row['value']);
            }

            if ($amount->isNegative()) {
                throw new DomainException('The installment schedule produces a negative installment.');
            }

            $allocated = $allocated->plus($amount);

            $number = $index + 1;
            $specs[] = new InstallmentSpec(
                number: $number,
                name: trim((string) ($row['name'] ?? '')) ?: "Installment {$number}",
                dueDate: Carbon::parse($row['due_date'])->startOfDay(),
                amount: $amount,
            );
        }

        return $specs;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertPercentagesSumTo100(array $rows): void
    {
        $sum = Money::zero();

        foreach ($rows as $row) {
            $pct = Money::of((string) $row['value']);

            if (! $pct->isPositive()) {
                throw new DomainException('Every installment percentage must be greater than 0.');
            }

            $sum = $sum->plus($pct);
        }

        if (! $sum->equals(Money::of('100'))) {
            throw new DomainException("Installment percentages must total exactly 100% (currently {$sum->store()}%).");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertAmountsSumTo(array $rows, Money $total): void
    {
        $sum = Money::zero();

        foreach ($rows as $row) {
            $amount = Money::of((string) $row['value']);

            if (! $amount->isPositive()) {
                throw new DomainException('Every installment amount must be greater than 0.');
            }

            $sum = $sum->plus($amount);
        }

        if (! $sum->equals($total)) {
            throw new DomainException(
                "Installment amounts (₹{$sum->store()}) must total exactly the plan amount (₹{$total->store()})."
            );
        }
    }
}
