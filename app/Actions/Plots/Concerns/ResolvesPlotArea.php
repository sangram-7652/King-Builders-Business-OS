<?php

declare(strict_types=1);

namespace App\Actions\Plots\Concerns;

use App\Enums\Masters\AreaUnit;
use App\Exceptions\DomainException;
use App\Models\Block;
use App\Models\Masters\PlotSize;
use App\Models\Project;

trait ResolvesPlotArea
{
    /**
     * A Block is OPTIONAL — a Plot may sit directly under its Project
     * instead (`$block === null`). When a Block IS given, it must belong to
     * the SAME project; cross-project assignment is always rejected.
     */
    protected function assertBlockBelongsToProject(Project $project, ?Block $block): void
    {
        if ($block !== null && $block->project_id !== $project->id) {
            throw new DomainException('The selected block does not belong to this project.');
        }
    }

    /**
     * Resolve the area/unit snapshot for a plot.
     *
     * If an explicit area is supplied it wins; otherwise it is copied from the
     * chosen Plot Size master AT THIS MOMENT and stored on the plot, so a later
     * edit to that master never rewrites the plot.
     *
     * @param  array<string, mixed>  $data
     * @return array{area: string|float, area_unit: string}
     */
    protected function resolveAreaSnapshot(array $data): array
    {
        $area = $data['area'] ?? null;
        $area = ($area === '' || $area === null) ? null : $area;

        $unit = $data['area_unit'] ?? null;
        $unit = ($unit === '' || $unit === null) ? null : $unit;

        if ($area === null && ! empty($data['plot_size_id'])) {
            $size = PlotSize::find($data['plot_size_id']);

            if ($size !== null) {
                $area = $size->area;
                $unit ??= $size->unit instanceof AreaUnit ? $size->unit->value : $size->unit;
            }
        }

        if ($area === null) {
            throw new DomainException('An area is required (either directly or via a plot size).');
        }

        return [
            'area' => $area,
            'area_unit' => $unit ?: AreaUnit::SquareFeet->value,
        ];
    }
}
