<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Payments\Concerns\RecalculatesLedger;
use App\Enums\PaymentPlanStatus;
use App\Exceptions\DomainException;
use App\Models\PaymentPlan;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

/**
 * DRAFT → ACTIVE. Re-checks that the installments still reconcile with the plan
 * total (and, unless variance is allowed, with the booking amount) and seeds
 * each installment's derived status. Idempotent.
 */
class ActivatePaymentPlanAction
{
    use RecalculatesLedger;
    use RunsInTransaction;

    public function handle(PaymentPlan $plan, User $actor): PaymentPlan
    {
        return $this->transaction(function () use ($plan, $actor): PaymentPlan {
            /** @var PaymentPlan $locked */
            $locked = PaymentPlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            $locked->load(['installments', 'booking']);

            if ($locked->status === PaymentPlanStatus::Active) {
                return $locked;
            }

            if (! $locked->canTransitionTo(PaymentPlanStatus::Active)) {
                throw new DomainException("A {$locked->status->label()} payment plan cannot be activated.");
            }

            $installmentSum = $locked->installments->reduce(
                fn (Money $carry, $i) => $carry->plus(Money::of($i->amount)),
                Money::zero(),
            );

            if (! $installmentSum->equals(Money::of($locked->total_amount))) {
                throw new DomainException('The installments no longer reconcile with the plan total.');
            }

            if (! $locked->allows_variance && ! $installmentSum->equals(Money::of($locked->booking->final_amount))) {
                throw new DomainException('The plan total no longer matches the booking amount.');
            }

            $locked->forceFill([
                'status' => PaymentPlanStatus::Active,
                'activated_at' => now(),
                'approved_by' => $actor->id,
            ])->save();

            $this->recalculatePlan($locked);

            Log::info('payment_plan.activated', [
                'payment_plan_id' => $locked->id,
                'booking_id' => $locked->booking_id,
                'by' => $actor->id,
            ]);

            return $locked->load('installments');
        });
    }
}
