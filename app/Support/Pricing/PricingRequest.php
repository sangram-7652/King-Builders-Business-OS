<?php

declare(strict_types=1);

namespace App\Support\Pricing;

/**
 * The full input to a booking price calculation: the base (area × rate) plus
 * every PLC / charge / discount / tax component to apply.
 */
final class PricingRequest
{
    /**
     * @param  list<PriceComponentInput>  $components
     */
    public function __construct(
        public readonly string $baseArea,
        public readonly string $baseRate,
        public readonly array $components = [],
    ) {}
}
