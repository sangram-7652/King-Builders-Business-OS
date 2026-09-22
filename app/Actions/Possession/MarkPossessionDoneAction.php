<?php

declare(strict_types=1);

namespace App\Actions\Possession;

use App\Actions\Registry\MarkRegistryDoneAction;
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
 * Marks a confirmed booking's Possession status as DONE — the ONLY write
 * path for the simplified Possession status (product requirement:
 * "Possession is simply PENDING or DONE"). No PossessionCase is created or
 * consulted; there is no eligibility gate (no collection percentage, no
 * document verification, no Registry dependency).
 *
 * Unlike Registry, this NEVER touches Plot status. Plot status is owned
 * exclusively by Registry (Pending → Booked, Done → Sold — see
 * {@see MarkRegistryDoneAction}); Possession is a
 * separate operational milestone (the physical handover) tracked
 * independently. If the plot happens to already be SOLD (because Registry
 * is Done), it stays exactly as it is.
 *
 * Idempotent: calling this again once already DONE is a safe no-op.
 */
class MarkPossessionDoneAction
{
    use RunsInTransaction;

    public function handle(Booking $booking, User $actor): Booking
    {
        if (! $actor->can(Permission::PossessionComplete->value)) {
            throw new DomainException('You are not authorised to change the Possession status.');
        }

        return $this->transaction(function () use ($booking, $actor): Booking {
            /** @var Booking $locked */
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isConfirmed()) {
                throw new DomainException('The Possession status can only be changed for a confirmed booking.');
            }

            if ($locked->possession_status === PossessionStatus::Done) {
                return $locked->fresh(['plot']);
            }

            $locked->forceFill(['possession_status' => PossessionStatus::Done])->save();

            PossessionTimeline::record(
                PossessionActivityType::PossessionStatusChanged,
                "Possession marked Done for {$locked->booking_number}.",
                $locked, $locked->plot, null,
                ['from' => PossessionStatus::Pending->value, 'to' => PossessionStatus::Done->value],
                $actor,
            );

            Log::info('booking.possession_status_changed', [
                'booking_id' => $locked->id,
                'booking_number' => $locked->booking_number,
                'from' => PossessionStatus::Pending->value,
                'to' => PossessionStatus::Done->value,
                'by' => $actor->id,
            ]);

            return $locked->fresh(['plot']);
        });
    }
}
