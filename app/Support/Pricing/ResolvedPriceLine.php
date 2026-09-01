<?php

declare(strict_types=1);

namespace App\Support\Pricing;

use App\Enums\PriceCalculationType;
use App\Enums\PriceComponentType;
use App\Support\Money;

/**
 * A price component after the engine has resolved it to a concrete amount.
 */
final class ResolvedPriceLine
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly PriceComponentType $type,
        public readonly string $name,
        public readonly PriceCalculationType $calculationType,
        public readonly ?string $quantity,
        public readonly string $rate,
        public readonly Money $amount,
        public readonly ?int $plcTypeId = null,
        public readonly ?int $chargeTypeId = null,
        public readonly ?int $taxRateId = null,
        public readonly int $sortOrder = 0,
        public readonly array $metadata = [],
    ) {}

    /**
     * Row attributes for `booking_price_lines`.
     *
     * @return array<string, mixed>
     */
    public function toLineAttributes(): array
    {
        return [
            'type' => $this->type->value,
            'name' => $this->name,
            'calculation_type' => $this->calculationType->value,
            'quantity' => $this->quantity,
            'rate' => $this->rate,
            'amount' => $this->amount->store(),
            'plc_type_id' => $this->plcTypeId,
            'charge_type_id' => $this->chargeTypeId,
            'tax_rate_id' => $this->taxRateId,
            'sort_order' => $this->sortOrder,
            'metadata' => $this->metadata === [] ? null : $this->metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'name' => $this->name,
            'calculation_type' => $this->calculationType->value,
            'quantity' => $this->quantity,
            'rate' => $this->rate,
            'amount' => $this->amount->store(),
            'plc_type_id' => $this->plcTypeId,
            'charge_type_id' => $this->chargeTypeId,
            'tax_rate_id' => $this->taxRateId,
            'metadata' => $this->metadata === [] ? null : $this->metadata,
        ];
    }
}
