<?php

declare(strict_types=1);

namespace App\Support\Commission;

use App\Enums\CommissionCalcType;
use App\Exceptions\DomainException;

/**
 * Validates and normalises the brackets of a SLAB commission rule (M14.3).
 *
 * A valid slab set has at least one bracket, brackets ordered by `from_amount`,
 * contiguous and non-overlapping (each `from_amount` equals the previous
 * `to_amount`), the first `from_amount` is 0, only the last `to_amount` is null
 * (open-ended), and every bracket is PERCENTAGE (with a rate) or FIXED (with an
 * amount). All comparisons use bcmath at scale 2 — never float arithmetic.
 *
 * @phpstan-type RawSlab array{from_amount?: string|int|float|null, to_amount?: string|int|float|null, calc_type?: string, rate?: string|int|float|null, flat_amount?: string|int|float|null}
 * @phpstan-type CleanSlab array{sort_order: int, from_amount: string, to_amount: string|null, calc_type: CommissionCalcType, rate: string|null, flat_amount: string|null}
 */
final class SlabSet
{
    private const SCALE = 2;

    /** @param  list<CleanSlab>  $slabs */
    private function __construct(public readonly array $slabs) {}

    /**
     * @param  iterable<RawSlab>  $rows
     *
     * @throws DomainException
     */
    public static function fromRows(iterable $rows): self
    {
        $raw = [];
        foreach ($rows as $row) {
            $raw[] = $row;
        }

        if ($raw === []) {
            throw new DomainException('A slab rule needs at least one bracket.');
        }

        // Order by from_amount so the caller need not pre-sort.
        usort($raw, static fn ($a, $b): int => bccomp(
            self::amount($a['from_amount'] ?? 0), self::amount($b['from_amount'] ?? 0), self::SCALE,
        ));

        $clean = [];
        $expectedFrom = '0.00';
        $last = count($raw) - 1;

        foreach ($raw as $i => $row) {
            $from = self::amount($row['from_amount'] ?? 0);
            $to = ($row['to_amount'] ?? null) === null || $row['to_amount'] === ''
                ? null
                : self::amount($row['to_amount']);

            if (bccomp($from, $expectedFrom, self::SCALE) !== 0) {
                throw new DomainException("Slab brackets must be contiguous — expected the bracket starting at {$expectedFrom}.");
            }

            if ($i !== $last && $to === null) {
                throw new DomainException('Only the last slab bracket may be open-ended.');
            }
            if ($i === $last && $to !== null) {
                throw new DomainException('The last slab bracket must be open-ended (no upper limit).');
            }
            if ($to !== null && bccomp($to, $from, self::SCALE) <= 0) {
                throw new DomainException('Each slab bracket must end above where it starts.');
            }

            $calcType = CommissionCalcType::tryFrom((string) ($row['calc_type'] ?? ''));
            if (! in_array($calcType, [CommissionCalcType::Percentage, CommissionCalcType::Fixed], true)) {
                throw new DomainException('A slab bracket must be a percentage or a fixed amount.');
            }

            [$rate, $flat] = self::rateOrAmount($calcType, $row);

            $clean[] = [
                'sort_order' => $i,
                'from_amount' => $from,
                'to_amount' => $to,
                'calc_type' => $calcType,
                'rate' => $rate,
                'flat_amount' => $flat,
            ];

            $expectedFrom = $to ?? $expectedFrom;
        }

        return new self($clean);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: string|null, 1: string|null}
     */
    private static function rateOrAmount(CommissionCalcType $calcType, array $row): array
    {
        if ($calcType === CommissionCalcType::Percentage) {
            $rate = self::amount($row['rate'] ?? 0, 4);
            if (bccomp($rate, '0', 4) < 0 || bccomp($rate, '100', 4) > 0) {
                throw new DomainException('A slab percentage must be between 0 and 100.');
            }

            return [$rate, null];
        }

        $flat = self::amount($row['flat_amount'] ?? 0);
        if (bccomp($flat, '0', self::SCALE) < 0) {
            throw new DomainException('A fixed slab amount cannot be negative.');
        }

        return [null, $flat];
    }

    private static function amount(mixed $value, int $scale = self::SCALE): string
    {
        $string = is_float($value)
            ? number_format($value, $scale, '.', '')
            : trim((string) ($value === null || $value === '' ? '0' : $value));

        if (! preg_match('/^\d+(\.\d+)?$/', $string)) {
            throw new DomainException("[{$string}] is not a valid slab amount.");
        }

        return bcadd($string, '0', $scale);
    }
}
