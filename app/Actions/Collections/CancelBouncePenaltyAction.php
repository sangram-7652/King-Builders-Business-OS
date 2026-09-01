<?php

declare(strict_types=1);

namespace App\Actions\Collections;

use App\Enums\PenaltyStatus;
use App\Exceptions\DomainException;
use App\Models\BouncePenalty;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;

/**
 * Cancels a bounce penalty (M8). `penalties.assess` (assessor may withdraw) or
 * `penalties.approve`. Idempotent.
 */
class CancelBouncePenaltyAction
{
    use RunsInTransaction;

    public function handle(BouncePenalty $penalty, User $actor, ?string $reason = null): BouncePenalty
    {
        if (! $actor->can('penalties.assess') && ! $actor->can('penalties.approve')) {
            throw new DomainException('You are not authorised to cancel a penalty.');
        }

        return $this->transaction(function () use ($penalty, $actor, $reason): BouncePenalty {
            /** @var BouncePenalty $locked */
            $locked = BouncePenalty::query()->whereKey($penalty->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === PenaltyStatus::Cancelled) {
                return $locked;
            }

            $locked->forceFill([
                'status' => PenaltyStatus::Cancelled,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            return $locked;
        });
    }
}
