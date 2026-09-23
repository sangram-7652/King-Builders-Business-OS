<?php

declare(strict_types=1);

namespace App\Actions\Plots;

use App\Actions\Plots\Concerns\ResolvesPlotArea;
use App\Enums\PlotStatus;
use App\Exceptions\DomainException;
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
    public function handle(Project $project, ?Block $block, array $data): Plot
    {
        $this->assertBlockBelongsToProject($project, $block);

        return $this->transaction(function () use ($project, $block, $data): Plot {
            $plotNumber = trim((string) $data['plot_number']);

            // Defense-in-depth beyond the (project_id, block_id, plot_number)
            // DB unique index: MySQL treats every NULL block_id as distinct,
            // so that index alone never catches a duplicate plot_number
            // between two DIRECT plots in the same project. Same convention
            // as BulkCreatePlots's own pre-check.
            $duplicate = Plot::withTrashed()
                ->where('project_id', $project->id)
                ->when($block !== null, fn ($q) => $q->where('block_id', $block->id), fn ($q) => $q->whereNull('block_id'))
                ->where('plot_number', $plotNumber)
                ->exists();

            if ($duplicate) {
                throw new DomainException($block !== null
                    ? 'This plot number already exists in this block.'
                    : 'This plot number already exists as a direct project plot.');
            }

            $snapshot = $this->resolveAreaSnapshot($data);

            $plot = Plot::create([
                'project_id' => $project->id,
                'block_id' => $block?->id,
                'plot_number' => $plotNumber,
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
                'status' => PlotStatus::Available,
                'is_active' => $data['is_active'] ?? true,
            ]);

            Log::info('plot.created', [
                'plot_id' => $plot->id,
                'project_id' => $project->id,
                'block_id' => $block?->id,
                'plot_number' => $plot->plot_number,
                'by' => auth()->id(),
            ]);

            return $plot;
        });
    }
}
