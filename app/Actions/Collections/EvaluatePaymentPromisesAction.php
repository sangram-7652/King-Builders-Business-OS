<?php

declare(strict_types=1);

namespace App\Actions\Collections;

use App\Actions\Collections\Concerns\SyncsCollectionCase;
use App\Enums\CollectionActivityType;
use App\Enums\CollectionCaseStatus;
use App\Enums\PaymentStatus;
use App\Enums\PromiseStatus;
use App\Models\Booking;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Services\Payments\PaymentLedger;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Re-evaluates a booking's OPEN promises against M7 truth (M8).
 *
 *  - KEPT  when the relevant outstanding has fallen by at least the promised
 *          amount since the promise was made (i.e. real SUCCESS payments landed)
 *  - BROKEN when the promise date has passed and it is still not fulfilled
 *
 * A promise is NEVER marked KEPT by a button — only by this rule. Run after
 * every payment event (via the observer / job) and nightly.
 */
class EvaluatePaymentPromisesAction
{
    use RunsInTransaction;
    use SyncsCollectionCase;

    public function __construct(private readonly PaymentLedger $ledger) {}

    public function handle(Booking $booking, ?Carbon $asOf = null): void
    {
        $today = ($asOf ?? Carbon::today())->startOfDay();

        $open = PaymentPromise::query()
            ->where('booking_id', $booking->getKey())
            ->where('status', PromiseStatus::Open->value)
            ->with(['installment', 'collectionCase.booking'])
            ->get();

        if ($open->isEmpty()) {
            return;
        }

        $this->transaction(function () use ($open, $booking, $today): void {
            foreach ($open as $promise) {
                /** @var PaymentPromise $locked */
                $locked = PaymentPromise::query()->whereKey($promise->getKey())->lockForUpdate()->first();

                if ($locked === null || $locked->status !== PromiseStatus::Open) {
                    continue;
                }

                $currentOutstanding = $locked->installment_id !== null
                    ? $this->ledger->installmentOutstanding($locked->installment ?? Installment::findOrFail($locked->installment_id))
                    : $this->ledger->bookingOutstanding($booking)->clampToZero();

                $progress = Money::of($locked->outstanding_at_creation)->minus($currentOutstanding);

                if (! $progress->lessThan(Money::of($locked->promised_amount))) {
                    $payment = Payment::query()
                        ->where('booking_id', $booking->id)
                        ->where('status', PaymentStatus::Success->value)
                        ->where('created_at', '>=', $locked->created_at)
                        ->latest('id')
                        ->first();

                    $locked->forceFill([
                        'status' => PromiseStatus::Kept,
                        'fulfilled_at' => now(),
                        'fulfilled_by_payment_id' => $payment?->id,
                    ])->save();

                    $locked->collectionCase?->recordActivity(
                        CollectionActivityType::PromiseKept,
                        "Promise of ₹{$locked->promised_amount} kept — payment received.",
                        ['promise_id' => $locked->id],
                    );

                    Log::info('payment_promise.kept', ['promise_id' => $locked->id, 'booking_id' => $booking->id]);
                } elseif ($locked->promise_date->copy()->startOfDay()->lt($today)) {
                    $locked->forceFill(['status' => PromiseStatus::Broken, 'broken_at' => now()])->save();

                    $locked->collectionCase?->recordActivity(
                        CollectionActivityType::PromiseBroken,
                        "Promise of ₹{$locked->promised_amount} broken — due ".$locked->promise_date->format('d M Y').'.',
                        ['promise_id' => $locked->id],
                    );

                    Log::info('payment_promise.broken', ['promise_id' => $locked->id, 'booking_id' => $booking->id]);
                }
            }

            $case = $booking->collectionCase()->with('booking')->first();

            if ($case !== null) {
                // Leave PROMISE_TO_PAY once there are no open promises.
                if ($case->status === CollectionCaseStatus::PromiseToPay
                    && ! $case->promises()->where('status', PromiseStatus::Open->value)->exists()) {
                    $case->forceFill(['status' => CollectionCaseStatus::InProgress])->save();
                }

                $this->syncCase($case);
            }
        });
    }
}
