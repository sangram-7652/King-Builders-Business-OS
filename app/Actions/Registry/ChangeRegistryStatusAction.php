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
 * The ONLY write path for the simplified, booking-level Registry status:
 *
 *   PENDING → DONE,  DONE → UNDONE,  UNDONE → DONE   ({@see RegistryStatus::transitionMap()})
 *
 * No RegistryCase is created or consulted and there is no eligibility gate
 * (no collection percentage, no document verification, no agreement check).
 *
 * Registry drives the booking's CURRENT plot (`bookings.plot_id`), atomically:
 *
 *   DONE   → plot Booked → Sold
 *   UNDONE → plot Sold   → Booked
 *
 * Only the current plot is ever touched — a plot released by an earlier Plot
 * Transfer is no longer this booking's plot and stays exactly as it is.
 *
 * Sold → Booked is deliberately NOT an edge of the generic
 * {@see PlotStatus::transitionMap()} (that map also drives the manual status
 * control on the plot screen, which must never un-sell a plot behind
 * Registry's back); it is performed here as a controlled operation, the same
 * way booking cancellation performs Booked → Available. A plot already in
 * the target inventory state is left alone; any other plot state (e.g. a
 * legacy POSSESSION_COMPLETED) is refused rather than silently overwritten.
 *
 * Idempotent: requesting the status the booking already has is a no-op.
 */
class ChangeRegistryStatusAction
{
    use RunsInTransaction;

    public function handle(Booking $booking, RegistryStatus $target, User $actor): Booking
    {
        if (! $actor->can(Permission::RegistryComplete->value)) {
            throw new DomainException('You are not authorised to change the Registry status.');
        }

        return $this->transaction(function () use ($booking, $target, $actor): Booking {
            /** @var Booking $locked */
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isConfirmed()) {
                throw new DomainException('The Registry status can only be changed for a confirmed booking.');
            }

            $from = $locked->registry_status;

            if ($from === $target) {
                return $locked->fresh(['plot']);
            }

            if (! $from->canTransitionTo($target)) {
                throw new DomainException("Registry cannot move from {$from->label()} to {$target->label()}.");
            }

            /** @var Plot $plot */
            $plot = Plot::query()->whereKey($locked->plot_id)->lockForUpdate()->firstOrFail();

            [$fromPlotStatus, $toPlotStatus] = $target === RegistryStatus::Done
                ? [PlotStatus::Booked, PlotStatus::Sold]
                : [PlotStatus::Sold, PlotStatus::Booked];

            if ($plot->status !== $toPlotStatus && $plot->status !== $fromPlotStatus) {
                throw new DomainException(
                    "Plot {$plot->plot_number} is {$plot->status->label()} and cannot become {$toPlotStatus->label()} — resolve this manually before marking Registry {$target->label()}."
                );
            }

            $previousPlotStatus = $plot->status;

            $locked->forceFill(['registry_status' => $target])->save();
            $plot->forceFill(['status' => $toPlotStatus])->save();

            DocumentTimeline::record(
                DocumentActivityType::RegistryStatusChanged,
                "Registry marked {$target->label()} for {$locked->booking_number} — Plot {$plot->plot_number} is now {$toPlotStatus->label()}.",
                $locked, null,
                ['from' => $from->value, 'to' => $target->value, 'plot_id' => $plot->id],
                $actor,
            );

            Log::info('booking.registry_status_changed', [
                'booking_id' => $locked->id,
                'booking_number' => $locked->booking_number,
                'from' => $from->value,
                'to' => $target->value,
                'plot_id' => $plot->id,
                'plot_from' => $previousPlotStatus->value,
                'plot_to' => $toPlotStatus->value,
                'by' => $actor->id,
            ]);

            return $locked->fresh(['plot']);
        });
    }
}
