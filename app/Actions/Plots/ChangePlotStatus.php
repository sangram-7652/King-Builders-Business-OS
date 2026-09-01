<?php

declare(strict_types=1);

namespace App\Actions\Plots;

use App\Enums\PlotStatus;
use App\Exceptions\DomainException;
use App\Models\Plot;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Generic, lifecycle-map-enforcing status change. Used for the transitions M4
 * does not own outright (HOLD → BOOKED, BOOKED → SOLD / CANCELLED) which are
 * ultimately driven by later modules but whose rules live in PlotStatus.
 *
 * AVAILABLE ⇄ HOLD go through HoldPlotAction / ReleasePlotHoldAction instead so
 * the hold metadata and row-locking are handled.
 *
 * TRANSFERRED is never reachable here — it needs a dedicated transfer workflow.
 */
class ChangePlotStatus
{
    use RunsInTransaction;

    public function handle(Plot $plot, PlotStatus $target): Plot
    {
        return $this->transaction(function () use ($plot, $target): Plot {
            $locked = Plot::query()->whereKey($plot->getKey())->lockForUpdate()->firstOrFail();
            $current = $locked->status;

            if ($current === $target) {
                return $locked;
            }

            if (! $current->canTransitionTo($target)) {
                throw new DomainException("A plot that is {$current->label()} cannot move to {$target->label()}.");
            }

            $locked->status = $target;

            // Leaving HOLD for any reason clears the hold metadata.
            if ($current === PlotStatus::Hold) {
                $locked->forceFill(Plot::clearedHoldAttributes());
            }

            $locked->save();

            Log::info('plot.status_changed', [
                'plot_id' => $locked->id,
                'from' => $current->value,
                'to' => $target->value,
                'by' => auth()->id(),
            ]);

            return $locked;
        });
    }
}
