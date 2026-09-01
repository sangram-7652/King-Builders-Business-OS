<?php

declare(strict_types=1);

namespace App\Support\Pricing;

use App\Enums\PriceCalculationType;
use App\Enums\PriceComponentType;

/**
 * One requested price component fed to the PricingEngine. Immutable, plain
 * data — no DB access, no money maths.
 */
final class PriceComponentInput
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly PriceComponentType $type,
        public readonly string $name,
        public readonly PriceCalculationType $calculationType,
        public readonly string $rate,
        public readonly ?string $quantity = null,
        public readonly ?int $plcTypeId = null,
        public readonly ?int $chargeTypeId = null,
        public readonly ?int $taxRateId = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'] instanceof PriceComponentType ? $data['type'] : PriceComponentType::from((string) $data['type']),
            name: (string) $data['name'],
            calculationType: $data['calculation_type'] instanceof PriceCalculationType
                ? $data['calculation_type']
                : PriceCalculationType::from((string) $data['calculation_type']),
            rate: (string) $data['rate'],
            quantity: isset($data['quantity']) && $data['quantity'] !== '' ? (string) $data['quantity'] : null,
            plcTypeId: $data['plc_type_id'] ?? null,
            chargeTypeId: $data['charge_type_id'] ?? null,
            taxRateId: $data['tax_rate_id'] ?? null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }
}
