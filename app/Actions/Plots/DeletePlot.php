<?php

declare(strict_types=1);

namespace App\Actions\Plots;

use App\Exceptions\DomainException;
use App\Models\Plot;
use Illuminate\Support\Facades\Log;

/**
 * Soft-deletes a plot. Refuses if downstream business data (bookings)
 * references it — that check lives in GuardsAgainstDestructiveDelete.
 */
class DeletePlot
{
    public function handle(Plot $plot): void
    {
        if ($plot->hasBusinessDependents()) {
            throw new DomainException(
                'This plot has dependent records ('.implode(', ', $plot->blockingDependents()).
                ') and cannot be deleted. Archive it instead.'
            );
        }

        $plot->delete();

        Log::info('plot.deleted', [
            'project_id' => $plot->project_id,
            'block_id' => $plot->block_id,
            'plot_id' => $plot->id,
            'by' => auth()->id(),
        ]);
    }
}
