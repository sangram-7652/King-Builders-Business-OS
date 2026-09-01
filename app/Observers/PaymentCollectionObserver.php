<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Collections\SyncBookingCollectionAction;
use App\Enums\PaymentStatus;
use App\Models\Payment;

/**
 * Keeps M8 collection state in step with M7 payment state (M8). Purely
 * reactive: when a payment's status settles, re-evaluate the booking's
 * promises and re-sync its collection case. Runs synchronously in the same
 * transaction as the payment change — it only reads M7 and writes M8.
 */
class PaymentCollectionObserver
{
    public function updated(Payment $payment): void
    {
        if (! $payment->wasChanged('status')) {
            return;
        }

        if (! in_array($payment->status, [PaymentStatus::Success, PaymentStatus::Failed, PaymentStatus::Reversed], true)) {
            return;
        }

        $payment->loadMissing('booking');

        if ($payment->booking !== null) {
            app(SyncBookingCollectionAction::class)->handle($payment->booking);
        }
    }
}
