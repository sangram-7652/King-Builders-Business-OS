<?php

declare(strict_types=1);

namespace App\Support\Payments;

use App\Services\Payments\PaymentLedger;
use App\Support\Money;

/**
 * Immutable snapshot of a booking's money position, produced by
 * {@see PaymentLedger}. Blade / Livewire read this —
 * they never recompute balances themselves.
 */
final class FinancialSummary
{
    public function __construct(
        public readonly Money $total,        // booking final_amount (frozen M6 snapshot)
        public readonly Money $planned,      // active payment plan total (0 if none)
        public readonly Money $paid,         // Σ SUCCESS payments
        public readonly Money $outstanding,  // total − paid
        public readonly Money $overdue,      // Σ outstanding on past-due, unsettled installments
        public readonly Money $unallocated,  // Σ (SUCCESS payment amount − its allocations)
        public readonly int $installmentCount = 0,
        public readonly int $settledInstallmentCount = 0,
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
            'planned' => $this->planned->store(),
            'paid' => $this->paid->store(),
            'outstanding' => $this->outstanding->store(),
            'overdue' => $this->overdue->store(),
            'unallocated' => $this->unallocated->store(),
            'installment_count' => $this->installmentCount,
            'settled_installment_count' => $this->settledInstallmentCount,
            'fully_paid' => $this->isFullyPaid(),
        ];
    }
}
