<?php

declare(strict_types=1);

namespace App\Support\Payments;

use App\Support\Money;

/**
 * Immutable snapshot of a booking's money position, produced by
 * {@see \App\Services\Payments\PaymentLedger}. Blade / Livewire read this —
 * they never recompute balances themselves.
 */
final class FinancialSummary
{
    public function __construct(
        public readonly Money $total,        // booking final_amount (frozen M6 snapshot)
        public readonly Money $paid,         // Σ SUCCESS payments
        public readonly Money $outstanding,  // total − paid
    ) {}

    public function isFullyPaid(): bool
    {
        return ! $this->outstanding->isPositive();
    }

    public function hasCredit(): bool
    {
        return $this->outstanding->isNegative();
    }

    /**
     * @return array<string, string|int|bool>
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total->store(),
            'paid' => $this->paid->store(),
            'outstanding' => $this->outstanding->store(),
            'fully_paid' => $this->isFullyPaid(),
        ];
    }
}
