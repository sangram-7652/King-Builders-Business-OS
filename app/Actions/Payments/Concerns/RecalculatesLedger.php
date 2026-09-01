<?php

declare(strict_types=1);

namespace App\Actions\Payments\Concerns;

use App\Enums\PaymentPlanStatus;
use App\Models\PaymentPlan;
use App\Services\Payments\PaymentLedger;

/**
 * Re-derives installment statuses (and the plan's completed/active state) from
 * the ledger after an allocation or a reversal. Call this inside the same
 * transaction that changed the money.
 */
trait RecalculatesLedger
{
    protected function recalculatePlan(PaymentPlan $plan): void
    {
        $ledger = app(PaymentLedger::class);
        $plan->load('installments');

        $allSettled = $plan->installments->isNotEmpty();

        foreach ($plan->installments as $installment) {
            $derived = $ledger->deriveInstallmentStatus($installment);

            if ($installment->status !== $derived) {
                $installment->status = $derived;
                $installment->save();
            }

            if (! $derived->isSettled()) {
                $allSettled = false;
            }
        }

        if ($plan->status === PaymentPlanStatus::Active && $allSettled) {
            $plan->forceFill(['status' => PaymentPlanStatus::Completed])->save();
        } elseif ($plan->status === PaymentPlanStatus::Completed && ! $allSettled) {
            // A reversal re-opened an obligation.
            $plan->forceFill(['status' => PaymentPlanStatus::Active])->save();
        }
    }
}
