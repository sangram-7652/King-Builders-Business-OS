<?php

declare(strict_types=1);

namespace App\Actions\Plots;

use App\Enums\PlotStatus;
use App\Exceptions\DomainException;
use App\Models\Plot;
use Illuminate\Support\Facades\Log;

/**
 * Archive / un-archive a plot (the `is_active` flag). A plot that is currently
 * on HOLD or otherwise mid-lifecycle cannot be archived — resolve its status
 * first.
 */
class TogglePlotActive
{
    public function handle(Plot $plot): Plot
    {
        if ($plot->is_active && ! in_array($plot->status, [PlotStatus::Available, PlotStatus::Cancelled], true)) {
            throw new DomainException("A plot that is {$plot->status->label()} cannot be archived.");
        }

        $plot->is_active = ! $plot->is_active;
        $plot->save();

        Log::info($plot->is_active ? 'plot.activated' : 'plot.archived', [
            'plot_id' => $plot->id,
            'by' => auth()->id(),
        ]);

        return $plot;
    }
}
