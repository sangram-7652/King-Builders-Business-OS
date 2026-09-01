<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\DomainException;
use Stringable;

/**
 * Immutable money value object for the pricing engine (M6).
 *
 * Financial values are NEVER handled as float/double anywhere in the booking
 * pipeline. All arithmetic here goes through bcmath at an internal scale of 6,
 * and only the final `->store()` / `->__toString()` rounds (HALF_UP) to the 2
 * decimal places the DECIMAL(15,2) columns hold.
 */
final class Money implements Stringable
{
    /** Working precision — wider than storage so chained ops don't drift. */
    private const SCALE = 6;

    /** Persisted / displayed precision. */
    public const STORE_SCALE = 2;

    private string $amount;

    private function __construct(string $amount)
    {
        $this->amount = bcadd($amount, '0', self::SCALE);
    }

    public static function of(string|int|float|self|null $value): self
    {
        if ($value instanceof self) {
            return new self($value->amount);
        }

        if ($value === null || $value === '') {
            return new self('0');
        }

        $string = is_float($value)
            ? number_format($value, self::SCALE, '.', '')
            : trim((string) $value);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $string)) {
            throw new DomainException("[{$string}] is not a valid monetary amount.");
        }

        return new self($string);
    }

    public static function zero(): self
    {
        return new self('0');
    }

    public function plus(self $other): self
    {
        return new self(bcadd($this->amount, $other->amount, self::SCALE));
    }

    public function minus(self $other): self
    {
        return new self(bcsub($this->amount, $other->amount, self::SCALE));
    }

    /** Multiply by a plain numeric factor (e.g. an area or a quantity). */
    public function multipliedBy(string|int|float $factor): self
    {
        $factor = is_float($factor) ? number_format($factor, self::SCALE, '.', '') : (string) $factor;

        return new self(bcmul($this->amount, $factor, self::SCALE));
    }

    /** This amount taken as a percentage rate applied to $base. */
    public function percentageOf(self $base): self
    {
        return new self(bcdiv(bcmul($base->amount, $this->amount, self::SCALE + 4), '100', self::SCALE));
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) < 0;
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) === 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->amount, '0', self::SCALE) > 0;
    }

    public function greaterThan(self $other): bool
    {
        return bccomp($this->amount, $other->amount, self::SCALE) > 0;
    }

    public function lessThan(self $other): bool
    {
        return bccomp($this->amount, $other->amount, self::SCALE) < 0;
    }

    /** Equality at storage precision (rounded), not internal precision. */
    public function equals(self $other): bool
    {
        return $this->store() === $other->store();
    }

    public static function min(self $a, self $b): self
    {
        return $a->lessThan($b) ? $a : $b;
    }

    public static function max(self $a, self $b): self
    {
        return $a->greaterThan($b) ? $a : $b;
    }

    /** Never let a running total fall below zero. */
    public function clampToZero(): self
    {
        return $this->isNegative() ? self::zero() : $this;
    }

    public function abs(): self
    {
        return $this->isNegative() ? new self(bcmul($this->amount, '-1', self::SCALE)) : $this;
    }

    /** HALF_UP rounding to storage precision, as a plain decimal string. */
    public function store(): string
    {
        $delta = '0.'.str_repeat('0', self::STORE_SCALE).'5';

        $rounded = $this->isNegative()
            ? bcsub($this->amount, $delta, self::STORE_SCALE)
            : bcadd($this->amount, $delta, self::STORE_SCALE);

        // Collapse a "-0.00" result to "0.00".
        return bccomp($rounded, '0', self::STORE_SCALE) === 0
            ? bcadd('0', '0', self::STORE_SCALE)
            : $rounded;
    }

    public function __toString(): string
    {
        return $this->store();
    }
}
