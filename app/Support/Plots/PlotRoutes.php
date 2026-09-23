<?php

declare(strict_types=1);

namespace App\Support\Plots;

use App\Models\Block;
use App\Models\Plot;
use App\Models\Project;

/**
 * A Block is OPTIONAL in the Project → Block → Plot hierarchy — a Plot may
 * instead sit directly under its Project. Rather than duplicating the Plot
 * management screens (PlotForm / PlotIndex / PlotShow / PlotBulkCreate) into
 * a second set of "direct plot" components, the SAME components serve both
 * contexts, reached through two route groups that share route basenames but
 * differ in prefix:
 *
 *   projects/{project}/blocks/{block}/plots/...   → route name "plots.*"
 *   projects/{project}/plots/...                  → route name "plots.direct.*"
 *
 * This class is the ONE place that decides which of the two a given
 * Plot/Block belongs to, so callers (Livewire redirects, Blade links) never
 * duplicate the `$block !== null ? ... : ...` branch themselves.
 */
final class PlotRoutes
{
    /** @param  'create'|'edit'|'show'|'index'|'bulk'  $suffix */
    public static function name(string $suffix, ?Block $block): string
    {
        return $block !== null ? "plots.{$suffix}" : "plots.direct.{$suffix}";
    }

    /** @return array<string, int> */
    public static function params(Project $project, ?Block $block, ?Plot $plot = null): array
    {
        $params = ['project' => $project->id];

        if ($block !== null) {
            $params['block'] = $block->id;
        }

        if ($plot !== null) {
            $params['plot'] = $plot->id;
        }

        return $params;
    }

    /** Route name + params for an EXISTING Plot, using its own block_id. */
    public static function forPlot(Plot $plot, string $suffix): array
    {
        return [
            'name' => $plot->block_id !== null ? "plots.{$suffix}" : "plots.direct.{$suffix}",
            'params' => $plot->block_id !== null
                ? ['project' => $plot->project_id, 'block' => $plot->block_id, 'plot' => $plot->id]
                : ['project' => $plot->project_id, 'plot' => $plot->id],
        ];
    }
}
