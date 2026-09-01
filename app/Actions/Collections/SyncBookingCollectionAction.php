<?php

declare(strict_types=1);

namespace App\Actions\Collections;

use App\Actions\Collections\Concerns\SyncsCollectionCase;
use App\Models\Booking;
use App\Models\CollectionCase;
use App\Observers\PaymentCollectionObserver;

/**
 * Reconciles a booking's collection state with M7 truth after a payment event
 * (M8). Called synchronously by {@see PaymentCollectionObserver}
 * and by the scheduled sweep. A no-op when the booking has no collection case
 * and no promises — a plain, on-time payment never spins up a case.
 */
class SyncBookingCollectionAction
{
    use SyncsCollectionCase;

    public function __construct(private readonly EvaluatePaymentPromisesAction $evaluatePromises) {}

    public function handle(Booking $booking): void
    {
        $case = CollectionCase::query()->where('booking_id', $booking->getKey())->first();
        $hasPromises = $booking->paymentPromises()->exists();

        if ($case === null && ! $hasPromises) {
            return;
        }

        $this->evaluatePromises->handle($booking);

        if ($case !== null) {
            $case->setRelation('booking', $booking);
            $this->syncCase($case->refresh());
        }
    }
}
