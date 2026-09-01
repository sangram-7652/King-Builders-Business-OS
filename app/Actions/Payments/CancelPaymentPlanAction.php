<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\PaymentPlanStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainException;
use App\Models\PaymentAllocation;
use App\Models\PaymentPlan;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Cancels a DRAFT or ACTIVE payment plan. Refused while any SUCCESS payment is
 * allocated to its installments — those must be reversed first so no money is
 * silently orphaned.
 */
class CancelPaymentPlanAction
{
    use RunsInTransaction;

    public function handle(PaymentPlan $plan, User $actor, ?string $reason = null): PaymentPlan
    {
        return $this->transaction(function () use ($plan, $actor, $reason): PaymentPlan {
            /** @var PaymentPlan $locked */
            $locked = PaymentPlan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentPlanStatus::Cancelled) {
                return $locked;
            }

            if (! $locked->canTransitionTo(PaymentPlanStatus::Cancelled)) {
                throw new DomainException("A {$locked->status->label()} payment plan cannot be cancelled.");
            }

            $hasSuccessfulAllocations = PaymentAllocation::query()
                ->whereIn('installment_id', $locked->installments()->select('id'))
                ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::Success->value))
                ->exists();

            if ($hasSuccessfulAllocations) {
                throw new DomainException('Reverse the payments allocated to this plan before cancelling it.');
            }

            $locked->forceFill([
                'status' => PaymentPlanStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
            ])->save();

            Log::info('payment_plan.cancelled', [
                'payment_plan_id' => $locked->id,
                'booking_id' => $locked->booking_id,
                'by' => $actor->id,
            ]);

            return $locked;
        });
    }
}
