<?php

declare(strict_types=1);

namespace App\Actions\Plots;

use App\Actions\Plots\Concerns\ResolvesPlotArea;
use App\Models\Plot;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Edits a plot's descriptive attributes. Status and hold metadata are never
 * touched here — those go through the lifecycle Actions.
 */
class UpdatePlot
{
    use ResolvesPlotArea;
    use RunsInTransaction;

    /**
     * @param  array<string, mixed>  $data  already-validated
     */
    public function handle(Plot $plot, array $data): Plot
    {
        return $this->transaction(function () use ($plot, $data): Plot {
            $snapshot = $this->resolveAreaSnapshot([
                'area' => $data['area'] ?? $plot->area,
                'area_unit' => $data['area_unit'] ?? null,
                'plot_size_id' => $data['plot_size_id'] ?? null,
            ]);

            $plot->fill([
                'plot_number' => trim((string) $data['plot_number']),
                'plot_category_id' => $data['plot_category_id'] ?: null,
                'plot_size_id' => $data['plot_size_id'] ?: null,
                'plot_dimension_id' => $data['plot_dimension_id'] ?: null,
                'area' => $snapshot['area'],
                'area_unit' => $snapshot['area_unit'],
                'facing' => $data['facing'] ?: null,
                'village_name' => ($data['village_name'] ?? null) ?: null,
                'gata_number' => ($data['gata_number'] ?? null) ?: null,
                'boundary_east' => ($data['boundary_east'] ?? null) ?: null,
                'boundary_west' => ($data['boundary_west'] ?? null) ?: null,
                'boundary_north' => ($data['boundary_north'] ?? null) ?: null,
                'boundary_south' => ($data['boundary_south'] ?? null) ?: null,
            ])->save();

            Log::info('plot.updated', [
                'plot_id' => $plot->id,
                'by' => auth()->id(),
            ]);

            return $plot->refresh();
        });
    }
}
