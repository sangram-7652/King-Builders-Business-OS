<?php

declare(strict_types=1);

namespace App\Actions\Collections;

use App\Actions\Collections\Concerns\SyncsCollectionCase;
use App\Enums\CollectionActivityType;
use App\Enums\CollectionCaseStatus;
use App\Enums\PromiseStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\CollectionCase;
use App\Models\Installment;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\Payments\PaymentLedger;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Records a promise to pay (M8).
 *
 *  - a promise is NOT a payment — it never touches the M7 ledger
 *  - amount > 0
 *  - amount ≤ the relevant M7 outstanding (installment or booking)
 *  - combined OPEN promises for the same scope may not exceed that outstanding
 *  - the case row is locked while this is checked, so two racing promises
 *    cannot jointly over-commit the outstanding
 */
class CreatePaymentPromiseAction
{
    use RunsInTransaction;
    use SyncsCollectionCase;

    public function __construct(
        private readonly EnsureCollectionCaseAction $ensureCase,
        private readonly PaymentLedger $ledger,
    ) {}

    /**
     * @param  array{booking_id: int, installment_id?: int|null, promised_amount: mixed, promise_date: string, notes?: string|null, idempotency_key?: string|null}  $data
     */
    public function handle(array $data, User $actor): PaymentPromise
    {
        $key = isset($data['idempotency_key']) && $data['idempotency_key'] !== ''
            ? (string) $data['idempotency_key'] : null;

        if ($key !== null && ($existing = PaymentPromise::query()->where('idempotency_key', $key)->first()) !== null) {
            return $existing;
        }

        $booking = Booking::query()->findOrFail($data['booking_id']);
        $amount = Money::of($data['promised_amount'] ?? null);

        if (! $amount->isPositive()) {
            throw new DomainException('A promised amount must be greater than zero.');
        }

        $case = $this->ensureCase->handle($booking, $actor);

        try {
            return $this->transaction(function () use ($case, $booking, $data, $actor, $amount, $key): PaymentPromise {
                /** @var CollectionCase $locked */
                $locked = CollectionCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();
                $locked->setRelation('booking', $booking);

                $installment = null;
                if (! empty($data['installment_id'])) {
                    /** @var Installment $installment */
                    $installment = Installment::query()->findOrFail($data['installment_id']);

                    if ($installment->paymentPlan->booking_id !== $booking->id) {
                        throw new DomainException('That installment does not belong to this booking.');
                    }
                }

                $outstanding = $installment !== null
                    ? $this->ledger->installmentOutstanding($installment)
                    : $this->ledger->bookingOutstanding($booking)->clampToZero();

                if (! $outstanding->isPositive()) {
                    throw new DomainException('There is nothing outstanding to promise against.');
                }

                if ($amount->greaterThan($outstanding)) {
                    throw new DomainException("The promised amount (₹{$amount->store()}) exceeds the outstanding (₹{$outstanding->store()}).");
                }

                $openPromised = PaymentPromise::query()
                    ->where('booking_id', $booking->id)
                    ->when($installment !== null,
                        fn ($q) => $q->where('installment_id', $installment->id),
                        fn ($q) => $q->whereNull('installment_id'),
                    )
                    ->where('status', PromiseStatus::Open->value)
                    ->sum('promised_amount');

                if (Money::of((string) $openPromised)->plus($amount)->greaterThan($outstanding)) {
                    throw new DomainException('Open promises for this outstanding would exceed the amount owed.');
                }

                $promise = $locked->promises()->create([
                    'booking_id' => $booking->id,
                    'installment_id' => $installment?->id,
                    'promised_amount' => $amount->store(),
                    'outstanding_at_creation' => $outstanding->store(),
                    'promise_date' => $data['promise_date'],
                    'status' => PromiseStatus::Open,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $actor->id,
                    'idempotency_key' => $key,
                ]);

                if ($locked->status !== CollectionCaseStatus::Resolved) {
                    $locked->forceFill(['status' => CollectionCaseStatus::PromiseToPay])->save();
                }

                $locked->recordActivity(
                    CollectionActivityType::PromiseCreated,
                    "Promise to pay ₹{$amount->store()} by ".$promise->promise_date->format('d M Y').'.',
                    ['promise_id' => $promise->id, 'amount' => $amount->store()],
                    $actor,
                );

                Log::info('payment_promise.created', [
                    'promise_id' => $promise->id,
                    'booking_id' => $booking->id,
                    'amount' => $promise->promised_amount,
                    'by' => $actor->id,
                ]);

                $this->syncCase($locked);

                return $promise;
            });
        } catch (QueryException $e) {
            if ($key !== null && ($existing = PaymentPromise::query()->where('idempotency_key', $key)->first()) !== null) {
                return $existing;
            }

            throw $e;
        }
    }
}
