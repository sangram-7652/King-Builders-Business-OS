<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Payments\Concerns\RecalculatesLedger;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainException;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\PaymentPlan;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * SUCCESS → REVERSED (M7). A confirmed payment is never deleted.
 *
 *  - `payments.reverse` only (policy re-checks) — EXCEPT when M8 triggers it as
 *    the consequence of an authorised cheque bounce (`$systemInitiated`), where
 *    the caller (RecordChequeBounceAction) has already checked `cheques.bounce`
 *  - a reason is mandatory and stored with who / when
 *  - allocation rows are KEPT (financial history stays intact) — they simply
 *    stop counting because the ledger only sums allocations of SUCCESS payments
 *  - affected installments + plan statuses are re-derived
 *  - the payment's receipt is voided (kept, marked), never deleted
 *  - idempotent: reversing an already-REVERSED payment is a no-op
 */
class ReversePaymentAction
{
    use RecalculatesLedger;
    use RunsInTransaction;

    public function handle(Payment $payment, string $reason, User $actor, bool $systemInitiated = false): Payment
    {
        if (! $systemInitiated && ! $actor->can('payments.reverse')) {
            throw new DomainException('You are not authorised to reverse a payment.');
        }

        if (trim($reason) === '') {
            throw new DomainException('A payment reversal needs a reason.');
        }

        return $this->transaction(function () use ($payment, $reason, $actor): Payment {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            $locked->load(['allocations', 'receipt']);

            if ($locked->status === PaymentStatus::Reversed) {
                return $locked;
            }

            if (! $locked->canTransitionTo(PaymentStatus::Reversed)) {
                throw new DomainException("A {$locked->status->label()} payment cannot be reversed.");
            }

            // Lock the installments whose balances will move.
            $installmentIds = $locked->allocations->pluck('installment_id')->unique()->all();

            /** @var Collection<int, Installment> $installments */
            $installments = $installmentIds === []
                ? collect()
                : Installment::query()->whereIn('id', $installmentIds)->lockForUpdate()->get();

            $locked->forceFill([
                'status' => PaymentStatus::Reversed,
                'reversed_by' => $actor->id,
                'reversed_at' => now(),
                'reversal_reason' => trim($reason),
            ])->save();

            if ($locked->receipt !== null && ! $locked->receipt->isVoided()) {
                $locked->receipt->forceFill([
                    'voided_at' => now(),
                    'voided_by' => $actor->id,
                    'void_reason' => 'Payment reversed: '.trim($reason),
                ])->save();
            }

            $planIds = $installments->pluck('payment_plan_id')->unique();

            foreach (PaymentPlan::query()->whereIn('id', $planIds)->get() as $plan) {
                $this->recalculatePlan($plan);
            }

            Log::info('payment.reversed', [
                'payment_id' => $locked->id,
                'payment_number' => $locked->payment_number,
                'amount' => $locked->amount,
                'by' => $actor->id,
            ]);

            return $locked->load(['allocations.installment', 'receipt']);
        });
    }
}
