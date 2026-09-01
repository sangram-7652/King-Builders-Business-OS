<?php

declare(strict_types=1);

namespace App\Actions\Collections;

use App\Actions\Collections\Concerns\SyncsCollectionCase;
use App\Actions\Payments\VerifyPaymentAction;
use App\Enums\CollectionActivityType;
use App\Enums\PaymentStatus;
use App\Exceptions\DomainException;
use App\Models\Payment;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;

/**
 * Clears a PENDING cheque payment (M8) — delegates the actual money move to M7
 * VerifyPaymentAction(SUCCESS), then records the collection timeline event.
 */
class MarkChequeClearedAction
{
    use RunsInTransaction;
    use SyncsCollectionCase;

    public function __construct(private readonly VerifyPaymentAction $verify) {}

    public function handle(Payment $payment, User $actor): Payment
    {
        if (! $actor->can('cheques.update')) {
            throw new DomainException('You are not authorised to update cheque status.');
        }

        $payment->loadMissing('paymentMode', 'booking.collectionCase');

        if (! (bool) $payment->paymentMode?->is_cheque && $payment->cheque_status === null) {
            throw new DomainException('That payment is not a cheque payment.');
        }

        if ($payment->status !== PaymentStatus::Pending) {
            throw new DomainException('Only a pending cheque can be cleared.');
        }

        $cleared = $this->verify->handle($payment, PaymentStatus::Success, $actor);

        $case = $payment->booking->collectionCase;
        if ($case !== null) {
            $case->loadMissing('booking');
            $case->recordActivity(
                CollectionActivityType::ChequeCleared,
                "Cheque {$cleared->cheque_number} cleared.",
                ['payment_id' => $cleared->id],
                $actor,
            );
            $this->syncCase($case);
        }

        return $cleared;
    }
}
