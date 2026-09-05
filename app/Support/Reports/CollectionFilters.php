<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Enums\AgingBucket;

/**
 * Collection-report-only filters (M11.4): ageing bucket + payment method.
 *
 * Not part of the shared {@see ReportFilterData} contract because only
 * `/reports/collections` uses them. Produced by
 * `ReportFilterResolver::resolveCollectionExtras()` — validated (enum / exists)
 * exactly once, there.
 *
 * `bucket` narrows the recovery table and highlights that ageing row; it never
 * changes the top-line KPIs (which always show the whole book).
 * `paymentModeId` narrows the payment-method breakdown only.
 */
final readonly class CollectionFilters
{
    public function __construct(
        public ?AgingBucket $bucket = null,
        public ?int $paymentModeId = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function isDefault(): bool
    {
        return $this->bucket === null && $this->paymentModeId === null;
    }

    /**
     * @return array<string, string>
     */
    public function toQueryString(): array
    {
        $out = [];
        if ($this->bucket !== null) {
            $out['ageing_bucket'] = $this->bucket->value;
        }
        if ($this->paymentModeId !== null) {
            $out['payment_method'] = (string) $this->paymentModeId;
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public function toFormValues(): array
    {
        return [
            'ageing_bucket' => $this->bucket?->value ?? '',
            'payment_method' => (string) ($this->paymentModeId ?? ''),
        ];
    }
}
