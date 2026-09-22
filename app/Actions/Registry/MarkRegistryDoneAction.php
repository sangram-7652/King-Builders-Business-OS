<?php

declare(strict_types=1);

namespace App\Actions\Registry;

use App\Enums\DocumentActivityType;
use App\Enums\Permission;
use App\Enums\PlotStatus;
use App\Enums\RegistryStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Plot;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Documents\DocumentTimeline;
use Illuminate\Support\Facades\Log;

/**
 * Marks a confirmed booking's Registry status as DONE — the ONLY write path
 * for the simplified Registry status (product requirement: "Registry is
 * simply PENDING or DONE, that is all"). No RegistryCase is created or
 * consulted; there is no eligibility gate (no collection percentage, no
 * document verification, no agreement check).
 *
 * Registry Done atomically drives the plot to SOLD — `bookings.registry_status`
 * is the single source of truth; Plot status is a DERIVED operational state
 * (PENDING → Booked, DONE → Sold), never a second independent status. If the
 * plot is not currently BOOKED (e.g. it already moved to SOLD /
 * POSSESSION_COMPLETED / TRANSFERRED / CANCELLED through some other path),
 * this refuses rather than silently overwriting it — see
 * {@see PlotStatus::transitionMap()}.
 *
 * Idempotent: calling this again once already DONE is a safe no-op.
 */
class MarkRegistryDoneAction
{
    use RunsInTransaction;

    public function handle(Booking $booking, User $actor): Booking
    {
        if (! $actor->can(Permission::RegistryComplete->value)) {
            throw new DomainException('You are not authorised to change the Registry status.');
        }

        return $this->transaction(function () use ($booking, $actor): Booking {
            /** @var Booking $locked */
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isConfirmed()) {
                throw new DomainException('The Registry status can only be changed for a confirmed booking.');
            }

            if ($locked->registry_status === RegistryStatus::Done) {
                return $locked->fresh(['plot']);
            }

            /** @var Plot $plot */
            $plot = Plot::query()->whereKey($locked->plot_id)->lockForUpdate()->firstOrFail();

            if (! $plot->status->canTransitionTo(PlotStatus::Sold)) {
                throw new DomainException(
                    "The plot is {$plot->status->label()} and cannot become Sold — resolve this manually before marking Registry as Done."
                );
            }

            $locked->forceFill(['registry_status' => RegistryStatus::Done])->save();
            $plot->forceFill(['status' => PlotStatus::Sold])->save();

            DocumentTimeline::record(
                DocumentActivityType::RegistryStatusChanged,
                "Registry marked Done for {$locked->booking_number}.",
                $locked, null,
                ['from' => RegistryStatus::Pending->value, 'to' => RegistryStatus::Done->value],
                $actor,
            );

            Log::info('booking.registry_status_changed', [
                'booking_id' => $locked->id,
                'booking_number' => $locked->booking_number,
                'from' => RegistryStatus::Pending->value,
                'to' => RegistryStatus::Done->value,
                'plot_id' => $plot->id,
                'by' => $actor->id,
            ]);

            return $locked->fresh(['plot']);
        });
    }
}
