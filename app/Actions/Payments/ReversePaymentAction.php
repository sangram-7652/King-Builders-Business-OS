<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\PaymentStatus;
use App\Exceptions\DomainException;
use App\Models\Payment;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * SUCCESS → REVERSED (M7). A confirmed payment is never deleted.
 *
 *  - `payments.reverse` only (policy re-checks) — EXCEPT when M8 triggers it as
 *    the consequence of an authorised cheque bounce (`$systemInitiated`), where
 *    the caller has already checked the relevant permission
 *  - a reason is mandatory and stored with who / when
 *  - the reversed payment stops counting toward the booking's Paid amount
 *    immediately — the ledger only sums SUCCESS payments — while the payment
 *    row itself is KEPT (financial history stays intact), never deleted
 *  - the payment's receipt is voided (kept, marked), never deleted
 *  - idempotent: reversing an already-REVERSED payment is a no-op
 */
class ReversePaymentAction
{
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
            $locked->load('receipt');

            if ($locked->status === PaymentStatus::Reversed) {
                return $locked;
            }

            if (! $locked->canTransitionTo(PaymentStatus::Reversed)) {
                throw new DomainException("A {$locked->status->label()} payment cannot be reversed.");
            }

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

            Log::info('payment.reversed', [
                'payment_id' => $locked->id,
                'payment_number' => $locked->payment_number,
                'amount' => $locked->amount,
                'by' => $actor->id,
            ]);

            return $locked->load('receipt');
        });
    }
}
