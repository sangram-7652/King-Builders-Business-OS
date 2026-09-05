<?php

declare(strict_types=1);

namespace App\Support\Commission;

use App\Enums\CommissionCalcType;
use App\Support\Money;

/**
 * The result of running a commission rule against a basis amount (M14.4).
 * Immutable. `gross` is the scheme-level commission (before any co-broker
 * split); `grossBeforeCaps` is that figure before the rule's min/max were
 * applied. All money is bcmath — never float.
 */
final class CommissionComputation
{
    /**
     * @param  list<array{label: string, detail: string, amount: string}>  $breakdown
     */
    public function __construct(
        public readonly CommissionCalcType $calcType,
        public readonly Money $basisAmount,
        public readonly Money $grossBeforeCaps,
        public readonly Money $gross,
        public readonly ?Money $minApplied,
        public readonly ?Money $maxApplied,
        public readonly array $breakdown,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'calc_type' => $this->calcType->value,
            'basis_amount' => $this->basisAmount->store(),
            'gross_before_caps' => $this->grossBeforeCaps->store(),
            'gross' => $this->gross->store(),
            'min_applied' => $this->minApplied?->store(),
            'max_applied' => $this->maxApplied?->store(),
            'breakdown' => $this->breakdown,
        ];
    }
}
