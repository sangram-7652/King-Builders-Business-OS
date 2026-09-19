<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\ChequeStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainException;
use App\Models\Payment;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Controlled verification: PENDING → SUCCESS or PENDING → FAILED (M7).
 *
 * Only an authorised user reaches this (policy + `payments.verify`). It is
 * idempotent: verifying a payment that is already in the requested state
 * returns it unchanged — no second receipt, no double count.
 *
 * On SUCCESS the payment's receipt is issued — a SUCCESS payment counts
 * toward the booking's Paid amount immediately, straight from
 * {@see \App\Services\Payments\PaymentLedger} — there is no separate
 * allocation step.
 */
class VerifyPaymentAction
{
    use RunsInTransaction;

    public function __construct(private readonly GenerateReceiptAction $receipts) {}

    public function handle(Payment $payment, PaymentStatus $outcome, User $actor): Payment
    {
        if (! in_array($outcome, [PaymentStatus::Success, PaymentStatus::Failed], true)) {
            throw new DomainException('A payment can only be verified as successful or failed.');
        }

        return $this->transaction(function () use ($payment, $outcome, $actor): Payment {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            $locked->loadMissing('paymentMode');

            if ($locked->status === $outcome) {
                return $locked->load('receipt');
            }

            if ($locked->status !== PaymentStatus::Pending) {
                throw new DomainException("Only a pending payment can be verified (this one is {$locked->status->label()}).");
            }

            $isCheque = (bool) $locked->paymentMode?->is_cheque;

            $locked->forceFill([
                'status' => $outcome,
                'verified_by' => $actor->id,
                'verified_at' => now(),
                'cheque_status' => $isCheque
                    ? ($outcome === PaymentStatus::Success ? ChequeStatus::Cleared : ChequeStatus::Bounced)
                    : $locked->cheque_status,
            ])->save();

            Log::info('payment.verified', [
                'payment_id' => $locked->id,
                'payment_number' => $locked->payment_number,
                'outcome' => $outcome->value,
                'by' => $actor->id,
            ]);

            if ($outcome === PaymentStatus::Success) {
                $this->receipts->handle($locked->fresh(['booking.primaryBookingBuyer.buyer', 'paymentMode']), $actor);
            }

            return $locked->load('receipt');
        });
    }
}
