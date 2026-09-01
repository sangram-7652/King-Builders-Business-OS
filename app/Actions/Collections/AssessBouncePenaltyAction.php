<?php

declare(strict_types=1);

namespace App\Actions\Collections;

use App\Enums\CollectionActivityType;
use App\Enums\PenaltyStatus;
use App\Exceptions\DomainException;
use App\Models\BouncePenalty;
use App\Models\ChequeBounce;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

/**
 * Explicitly assesses a bounce penalty (M8) — the foundation only. The penalty
 * is ASSESSED (pending approval) and is NOT posted to the M7 booking total.
 */
class AssessBouncePenaltyAction
{
    use RunsInTransaction;

    /**
     * @param  array{penalty_amount: mixed, reason: string}  $data
     */
    public function handle(ChequeBounce $bounce, array $data, User $actor): BouncePenalty
    {
        if (! $actor->can('penalties.assess')) {
            throw new DomainException('You are not authorised to assess a penalty.');
        }

        $amount = Money::of($data['penalty_amount'] ?? null);

        if (! $amount->isPositive()) {
            throw new DomainException('A penalty amount must be greater than zero.');
        }

        if (trim((string) ($data['reason'] ?? '')) === '') {
            throw new DomainException('A penalty reason is required.');
        }

        return $this->transaction(function () use ($bounce, $data, $actor, $amount): BouncePenalty {
            $penalty = BouncePenalty::create([
                'cheque_bounce_id' => $bounce->id,
                'booking_id' => $bounce->booking_id,
                'penalty_amount' => $amount->store(),
                'reason' => trim($data['reason']),
                'status' => PenaltyStatus::Assessed,
                'assessed_by' => $actor->id,
                'assessed_at' => now(),
            ]);

            $bounce->collectionCase?->loadMissing('booking');
            $bounce->collectionCase?->recordActivity(
                CollectionActivityType::PenaltyAssessed,
                "Bounce penalty of ₹{$amount->store()} assessed.",
                ['penalty_id' => $penalty->id, 'amount' => $amount->store()],
                $actor,
            );

            Log::info('bounce_penalty.assessed', [
                'penalty_id' => $penalty->id,
                'booking_id' => $bounce->booking_id,
                'amount' => $penalty->penalty_amount,
                'by' => $actor->id,
            ]);

            return $penalty;
        });
    }
}
