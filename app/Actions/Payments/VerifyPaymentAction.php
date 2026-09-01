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
 * returns it unchanged — no second receipt, no re-allocation, no double count.
 *
 * On SUCCESS the payment is auto-allocated (oldest installment first, or an
 * explicit split for `payments.allocate` holders) and its receipt is issued —
 * all inside one transaction with the payment row locked.
 */
class VerifyPaymentAction
{
    use RunsInTransaction;

    public function __construct(
        private readonly AllocatePaymentAction $allocator,
        private readonly GenerateReceiptAction $receipts,
    ) {}

    /**
     * @param  list<array{installment_id: int|string, amount: mixed}>|null  $allocations
     */
    public function handle(Payment $payment, PaymentStatus $outcome, User $actor, ?array $allocations = null): Payment
    {
        if (! in_array($outcome, [PaymentStatus::Success, PaymentStatus::Failed], true)) {
            throw new DomainException('A payment can only be verified as successful or failed.');
        }

        return $this->transaction(function () use ($payment, $outcome, $actor, $allocations): Payment {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            $locked->loadMissing('paymentMode');

            if ($locked->status === $outcome) {
                return $locked->load(['allocations.installment', 'receipt']);
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
                $this->allocator->allocate($locked, $allocations, $actor);
                $this->receipts->handle($locked->fresh(['booking.primaryBookingBuyer.buyer', 'paymentMode']), $actor);
            }

            return $locked->load(['allocations.installment', 'receipt']);
        });
    }
}
