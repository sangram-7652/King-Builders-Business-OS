<?php

declare(strict_types=1);

namespace App\Support\Pricing;

/**
 * Version tag stored inside every price snapshot. Bump it whenever the engine's
 * calculation order or rounding rules change, so an old snapshot is always
 * interpretable against the rules that produced it.
 */
final class PricingEngineVersion
{
    public const CURRENT = '1.0';
}
