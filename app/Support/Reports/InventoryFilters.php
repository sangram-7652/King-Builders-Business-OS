<?php

declare(strict_types=1);

namespace App\Support\Reports;

/**
 * Inventory-report-only filters (M11.3): price and size ranges.
 *
 * These are not part of the shared {@see ReportFilterData} contract because
 * only `/reports/inventory` uses them. Produced by
 * `ReportFilterResolver::resolveInventoryExtras()`; validated (numeric, ≥ 0,
 * min ≤ max) exactly once, there.
 *
 * Price ranges only apply where a real transacted price exists (a booking's
 * M6 `final_amount`) — an *available* plot has no stored price, so a price
 * filter never hides available inventory.
 */
final readonly class InventoryFilters
{
    public function __construct(
        public ?float $priceMin = null,
        public ?float $priceMax = null,
        public ?float $sizeMin = null,
        public ?float $sizeMax = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function isDefault(): bool
    {
        return $this->priceMin === null && $this->priceMax === null
            && $this->sizeMin === null && $this->sizeMax === null;
    }

    public function hasPriceRange(): bool
    {
        return $this->priceMin !== null || $this->priceMax !== null;
    }

    public function hasSizeRange(): bool
    {
        return $this->sizeMin !== null || $this->sizeMax !== null;
    }

    /**
     * @return array<string, string>
     */
    public function toQueryString(): array
    {
        $out = [];
        if ($this->priceMin !== null) {
            $out['price_min'] = self::num($this->priceMin);
        }
        if ($this->priceMax !== null) {
            $out['price_max'] = self::num($this->priceMax);
        }
        if ($this->sizeMin !== null) {
            $out['size_min'] = self::num($this->sizeMin);
        }
        if ($this->sizeMax !== null) {
            $out['size_max'] = self::num($this->sizeMax);
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public function toFormValues(): array
    {
        return [
            'price_min' => $this->priceMin === null ? '' : self::num($this->priceMin),
            'price_max' => $this->priceMax === null ? '' : self::num($this->priceMax),
            'size_min' => $this->sizeMin === null ? '' : self::num($this->sizeMin),
            'size_max' => $this->sizeMax === null ? '' : self::num($this->sizeMax),
        ];
    }

    private static function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
