<?php

declare(strict_types=1);

namespace App\Actions\Collections;

use App\Enums\CollectionActivityType;
use App\Enums\PenaltyStatus;
use App\Exceptions\DomainException;
use App\Models\BouncePenalty;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Approves an assessed bounce penalty (M8). `penalties.approve` only.
 * Idempotent. Does NOT post anything to the M7 financial total — accounting
 * posting is a later milestone.
 */
class ApproveBouncePenaltyAction
{
    use RunsInTransaction;

    public function handle(BouncePenalty $penalty, User $actor): BouncePenalty
    {
        if (! $actor->can('penalties.approve')) {
            throw new DomainException('You are not authorised to approve a penalty.');
        }

        return $this->transaction(function () use ($penalty, $actor): BouncePenalty {
            /** @var BouncePenalty $locked */
            $locked = BouncePenalty::query()->whereKey($penalty->getKey())->lockForUpdate()->firstOrFail();
            $locked->load('chequeBounce.collectionCase.booking');

            if ($locked->status === PenaltyStatus::Approved) {
                return $locked;
            }

            if ($locked->status !== PenaltyStatus::Assessed) {
                throw new DomainException("A {$locked->status->label()} penalty cannot be approved.");
            }

            $locked->forceFill([
                'status' => PenaltyStatus::Approved,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ])->save();

            $locked->chequeBounce->collectionCase?->recordActivity(
                CollectionActivityType::PenaltyApproved,
                "Bounce penalty of ₹{$locked->penalty_amount} approved.",
                ['penalty_id' => $locked->id],
                $actor,
            );

            Log::info('bounce_penalty.approved', [
                'penalty_id' => $locked->id,
                'booking_id' => $locked->booking_id,
                'by' => $actor->id,
            ]);

            return $locked;
        });
    }
}
