<?php

declare(strict_types=1);

namespace App\Actions\Plots;

use App\Actions\Plots\Concerns\ResolvesPlotArea;
use App\Enums\PlotStatus;
use App\Models\Block;
use App\Models\Plot;
use App\Models\Project;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

class CreatePlot
{
    use ResolvesPlotArea;
    use RunsInTransaction;

    /**
     * @param  array<string, mixed>  $data  already-validated
     */
    public function handle(Project $project, Block $block, array $data): Plot
    {
        $this->assertBlockBelongsToProject($project, $block);

        return $this->transaction(function () use ($project, $block, $data): Plot {
            $snapshot = $this->resolveAreaSnapshot($data);

            $plot = Plot::create([
                'project_id' => $project->id,
                'block_id' => $block->id,
                'plot_number' => trim((string) $data['plot_number']),
                'plot_category_id' => $data['plot_category_id'] ?: null,
                'plot_size_id' => $data['plot_size_id'] ?: null,
                'plot_dimension_id' => $data['plot_dimension_id'] ?: null,
                'area' => $snapshot['area'],
                'area_unit' => $snapshot['area_unit'],
                'facing' => $data['facing'] ?: null,
                'status' => PlotStatus::Available,
                'is_active' => $data['is_active'] ?? true,
            ]);

            Log::info('plot.created', [
                'plot_id' => $plot->id,
                'project_id' => $project->id,
                'block_id' => $block->id,
                'plot_number' => $plot->plot_number,
                'by' => auth()->id(),
            ]);

            return $plot;
        });
    }
}
