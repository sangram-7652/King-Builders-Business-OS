<?php

declare(strict_types=1);

namespace App\Actions\Collections;

use App\Actions\Collections\Concerns\SyncsCollectionCase;
use App\Enums\CollectionActivityType;
use App\Enums\CollectionCaseStatus;
use App\Enums\CollectionPriority;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\CollectionCase;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Database\QueryException;

/**
 * Idempotently ensures a collection case exists for a CONFIRMED booking (M8).
 * One case per booking (DB unique). Safe to call repeatedly and concurrently.
 */
class EnsureCollectionCaseAction
{
    use RunsInTransaction;
    use SyncsCollectionCase;

    public function handle(Booking $booking, ?User $actor = null): CollectionCase
    {
        if (! $booking->isConfirmed()) {
            throw new DomainException('A collection case only exists for a confirmed booking.');
        }

        if (($existing = CollectionCase::query()->where('booking_id', $booking->getKey())->first()) !== null) {
            return $existing;
        }

        try {
            return $this->transaction(function () use ($booking, $actor): CollectionCase {
                $case = CollectionCase::create([
                    'booking_id' => $booking->id,
                    'status' => CollectionCaseStatus::Open,
                    'priority' => CollectionPriority::Low,
                    'opened_at' => now(),
                    'opened_by' => $actor?->id,
                ]);

                $case->setRelation('booking', $booking);
                $case->recordActivity(CollectionActivityType::CaseOpened, 'Collection case opened.', [], $actor);

                return $this->syncCase($case);
            });
        } catch (QueryException $e) {
            if (($existing = CollectionCase::query()->where('booking_id', $booking->getKey())->first()) !== null) {
                return $existing;
            }

            throw $e;
        }
    }
}
