<?php

declare(strict_types=1);

namespace App\Actions\Possession;

use App\Actions\Registry\ChangeRegistryStatusAction;
use App\Enums\Permission;
use App\Enums\PossessionActivityType;
use App\Enums\PossessionStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Possession\PossessionTimeline;
use Illuminate\Support\Facades\Log;

/**
 * The ONLY write path for the simplified, booking-level Possession status:
 *
 *   PENDING → DONE,  DONE → UNDONE,  UNDONE → DONE   ({@see PossessionStatus::transitionMap()})
 *
 * No PossessionCase is created or consulted and there is no eligibility gate
 * (no collection percentage, no document verification, no Registry
 * dependency).
 *
 * This NEVER touches Plot status or Registry status. Plot status is owned
 * exclusively by Registry ({@see ChangeRegistryStatusAction}); Possession is
 * a separate operational milestone (the physical handover) tracked
 * independently.
 *
 * Idempotent: requesting the status the booking already has is a no-op.
 */
class ChangePossessionStatusAction
{
    use RunsInTransaction;

    public function handle(Booking $booking, PossessionStatus $target, User $actor): Booking
    {
        if (! $actor->can(Permission::PossessionComplete->value)) {
            throw new DomainException('You are not authorised to change the Possession status.');
        }

        return $this->transaction(function () use ($booking, $target, $actor): Booking {
            /** @var Booking $locked */
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isConfirmed()) {
                throw new DomainException('The Possession status can only be changed for a confirmed booking.');
            }

            $from = $locked->possession_status;

            if ($from === $target) {
                return $locked->fresh(['plot']);
            }

            if (! $from->canTransitionTo($target)) {
                throw new DomainException("Possession cannot move from {$from->label()} to {$target->label()}.");
            }

            $locked->forceFill(['possession_status' => $target])->save();

            PossessionTimeline::record(
                PossessionActivityType::PossessionStatusChanged,
                "Possession marked {$target->label()} for {$locked->booking_number}.",
                $locked, $locked->plot, null,
                ['from' => $from->value, 'to' => $target->value],
                $actor,
            );

            Log::info('booking.possession_status_changed', [
                'booking_id' => $locked->id,
                'booking_number' => $locked->booking_number,
                'from' => $from->value,
                'to' => $target->value,
                'by' => $actor->id,
            ]);

            return $locked->fresh(['plot']);
        });
    }
}
