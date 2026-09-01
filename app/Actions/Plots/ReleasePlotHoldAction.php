<?php

declare(strict_types=1);

namespace App\Actions\Plots;

use App\Enums\PlotStatus;
use App\Exceptions\DomainException;
use App\Models\Plot;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Returns a HOLD plot to AVAILABLE and clears the hold metadata.
 * Transactional + row-locked, symmetrically with HoldPlotAction.
 */
class ReleasePlotHoldAction
{
    use RunsInTransaction;

    /**
     * @param  string  $context  'manual' | 'expiry' — for the audit log
     */
    public function handle(int $plotId, string $context = 'manual'): Plot
    {
        return $this->transaction(function () use ($plotId, $context): Plot {
            /** @var Plot $plot */
            $plot = Plot::query()->whereKey($plotId)->lockForUpdate()->firstOrFail();

            if ($plot->status !== PlotStatus::Hold) {
                throw new DomainException("This plot is {$plot->status->label()} — there is no hold to release.");
            }

            $plot->forceFill([
                'status' => PlotStatus::Available,
                ...Plot::clearedHoldAttributes(),
            ])->save();

            Log::info('plot.hold_released', [
                'plot_id' => $plot->id,
                'context' => $context,
                'by' => auth()->id(),
            ]);

            return $plot;
        });
    }
}
