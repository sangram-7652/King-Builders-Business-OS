<?php

declare(strict_types=1);

namespace App\Actions\Collections;

use App\Actions\Collections\Concerns\SyncsCollectionCase;
use App\Enums\CollectionActivityType;
use App\Enums\PromiseStatus;
use App\Exceptions\DomainException;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;

/**
 * Cancels an OPEN promise (M8). Idempotent for an already-cancelled promise;
 * KEPT / BROKEN promises are historical and cannot be cancelled.
 */
class CancelPaymentPromiseAction
{
    use RunsInTransaction;
    use SyncsCollectionCase;

    public function handle(PaymentPromise $promise, User $actor, ?string $reason = null): PaymentPromise
    {
        return $this->transaction(function () use ($promise, $actor, $reason): PaymentPromise {
            /** @var PaymentPromise $locked */
            $locked = PaymentPromise::query()->whereKey($promise->getKey())->lockForUpdate()->firstOrFail();
            $locked->load('collectionCase.booking');

            if ($locked->status === PromiseStatus::Cancelled) {
                return $locked;
            }

            if ($locked->status !== PromiseStatus::Open) {
                throw new DomainException("A {$locked->status->label()} promise cannot be cancelled.");
            }

            $locked->forceFill([
                'status' => PromiseStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
            ])->save();

            $locked->collectionCase->recordActivity(
                CollectionActivityType::PromiseCancelled,
                'Promise cancelled.',
                ['promise_id' => $locked->id],
                $actor,
            );

            $this->syncCase($locked->collectionCase);

            return $locked;
        });
    }
}
