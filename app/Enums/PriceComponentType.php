<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * The kinds of line that make up a booking's price (M6 pricing engine).
 *
 *   BASE      area × rate — exactly one per booking
 *   PLC       preferential-location charges (corner, park facing, …)
 *   CHARGE    other charges (development, maintenance, legal, …)
 *   DISCOUNT  reduces the subtotal — stored as a positive amount, applied negative
 *   TAX       computed on the taxable amount (subtotal − discount)
 *
 * The engine always evaluates lines in this order; `sortWeight()` drives it.
 */
enum PriceComponentType: string
{
    use HasLabel;

    case Base = 'base';
    case Plc = 'plc';
    case Charge = 'charge';
    case Discount = 'discount';
    case Tax = 'tax';

    public function label(): string
    {
        return match ($this) {
            self::Base => 'Base price',
            self::Plc => 'PLC',
            self::Charge => 'Charge',
            self::Discount => 'Discount',
            self::Tax => 'Tax',
        };
    }

    /** Evaluation / display order. */
    public function sortWeight(): int
    {
        return match ($this) {
            self::Base => 0,
            self::Plc => 10,
            self::Charge => 20,
            self::Discount => 30,
            self::Tax => 40,
        };
    }

    /** Discounts subtract; everything else adds. */
    public function isDeduction(): bool
    {
        return $this === self::Discount;
    }
}
