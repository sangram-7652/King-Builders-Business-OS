<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Payments\Concerns\RecalculatesLedger;
use App\Enums\PaymentPlanStatus;
use App\Exceptions\DomainException;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Payments\PaymentLedger;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Applies a SUCCESS payment's money to installments (M7).
 *
 *  - default strategy: OLDEST due / outstanding installment first
 *  - explicit allocation allowed for `payments.allocate` holders
 *  - each allocation > 0, ≤ installment outstanding, and the running total
 *    ≤ the payment's unallocated amount
 *  - a (payment, installment) pair is unique — a retried request never
 *    double-counts (idempotent)
 *  - overpayment is never hidden: leftover money stays UNALLOCATED, tracked
 *
 * Installment rows are locked FOR UPDATE so two concurrent allocations cannot
 * both consume the same outstanding balance.
 */
class AllocatePaymentAction
{
    use RecalculatesLedger;
    use RunsInTransaction;

    public function __construct(private readonly PaymentLedger $ledger) {}

    /**
     * @param  list<array{installment_id: int|string, amount: mixed}>|null  $explicit
     */
    public function handle(Payment $payment, ?array $explicit, User $actor): Payment
    {
        return $this->transaction(function () use ($payment, $explicit, $actor): Payment {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isSuccessful()) {
                throw new DomainException('Only a successful payment can be allocated.');
            }

            $this->allocate($locked, $explicit, $actor);

            return $locked->load(['allocations.installment']);
        });
    }

    /**
     * Core allocation logic. The caller MUST already be in a transaction with
     * `$payment` locked and in SUCCESS state.
     *
     * @param  list<array{installment_id: int|string, amount: mixed}>|null  $explicit
     */
    public function allocate(Payment $payment, ?array $explicit, User $actor): void
    {
        $unallocated = $this->ledger->paymentUnallocated($payment);

        if ($unallocated->isZero()) {
            return; // fully allocated already — nothing to do (idempotent)
        }

        $payment->loadMissing('booking.activePaymentPlan');
        $plan = $payment->booking->activePaymentPlan;

        if ($plan === null || $plan->status !== PaymentPlanStatus::Active) {
            return; // no live plan — money stays unallocated, tracked
        }

        /** @var Collection<int, Installment> $installments */
        $installments = Installment::query()
            ->where('payment_plan_id', $plan->getKey())
            ->lockForUpdate()
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->get();

        $existingPairs = PaymentAllocation::query()
            ->where('payment_id', $payment->getKey())
            ->pluck('installment_id')
            ->all();

        if ($explicit !== null) {
            if (! $actor->can('payments.allocate')) {
                throw new DomainException('You are not authorised to allocate a payment manually.');
            }

            foreach ($explicit as $row) {
                $installment = $installments->firstWhere('id', (int) $row['installment_id']);

                if ($installment === null) {
                    throw new DomainException('That installment is not part of this booking\'s active plan.');
                }

                if (in_array($installment->id, $existingPairs, true)) {
                    continue; // pair already allocated — skip (idempotent)
                }

                $amount = Money::of($row['amount']);

                if (! $amount->isPositive()) {
                    throw new DomainException('An allocation amount must be greater than zero.');
                }

                $outstanding = $this->ledger->installmentOutstanding($installment);

                if ($amount->greaterThan($outstanding)) {
                    throw new DomainException("₹{$amount->store()} exceeds the outstanding on {$installment->label()} (₹{$outstanding->store()}).");
                }

                if ($amount->greaterThan($unallocated)) {
                    throw new DomainException('The allocations exceed the payment\'s unallocated amount.');
                }

                $this->writeAllocation($payment, $installment, $amount, $actor, auto: false);
                $unallocated = $unallocated->minus($amount);
            }
        } else {
            foreach ($installments as $installment) {
                if ($unallocated->isZero()) {
                    break;
                }

                if (in_array($installment->id, $existingPairs, true)) {
                    continue;
                }

                $outstanding = $this->ledger->installmentOutstanding($installment);

                if ($outstanding->isZero()) {
                    continue;
                }

                $take = Money::min($unallocated, $outstanding);
                $this->writeAllocation($payment, $installment, $take, $actor, auto: true);
                $unallocated = $unallocated->minus($take);
            }
        }

        $this->recalculatePlan($plan);

        Log::info('payment.allocated', [
            'payment_id' => $payment->id,
            'payment_number' => $payment->payment_number,
            'unallocated_remaining' => $unallocated->store(),
            'by' => $actor->id,
        ]);
    }

    private function writeAllocation(Payment $payment, Installment $installment, Money $amount, User $actor, bool $auto): void
    {
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'installment_id' => $installment->id,
            'amount' => $amount->store(),
            'allocated_by' => $actor->id,
            'is_auto' => $auto,
        ]);
    }
}
