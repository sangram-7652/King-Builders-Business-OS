<?php

declare(strict_types=1);

namespace App\Livewire\Plots;

use App\Models\Block;
use App\Models\Plot;
use App\Models\Project;
use App\Support\Plots\PlotStatusCounts;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Project-wide plot inventory — nested in the project detail "Inventory"
 * tab. Shows BOTH kinds of plot from the same screen: Block-based plots
 * grouped under their Block, then Direct Project Plots (no Block).
 */
class ProjectInventory extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
    }

    #[Computed]
    public function counts(): PlotStatusCounts
    {
        return PlotStatusCounts::for(Plot::query()->where('project_id', $this->project->id));
    }

    /**
     * EVERY plot of the project — `plots.project_id = project`, regardless of
     * whether `block_id` is set — in ONE query, then grouped in memory. Never
     * "only through blocks" and never "only block_id IS NULL", so neither
     * kind of plot can silently disappear from the inventory.
     *
     * @return Collection<int, Plot>
     */
    #[Computed]
    public function plots(): Collection
    {
        return Plot::query()
            ->where('project_id', $this->project->id)
            ->orderBy('plot_number')
            ->get(['id', 'project_id', 'block_id', 'plot_number', 'area', 'area_unit', 'status', 'is_active']);
    }

    /**
     * Every Block of the project (including ones with no plots yet), each
     * paired with its plots from {@see plots()}.
     *
     * @return Collection<int, array{block: Block, plots: Collection<int, Plot>}>
     */
    #[Computed]
    public function blockGroups(): Collection
    {
        $byBlock = $this->plots->whereNotNull('block_id')->groupBy('block_id');

        return $this->project->blocks()
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->map(fn (Block $block) => ['block' => $block, 'plots' => $byBlock->get($block->id, collect())->values()]);
    }

    /**
     * Plots that sit directly under the project, with no Block — a Block is
     * OPTIONAL in the Project → Block → Plot hierarchy.
     *
     * @return Collection<int, Plot>
     */
    #[Computed]
    public function directPlots(): Collection
    {
        return $this->plots->whereNull('block_id')->values();
    }

    public function render(): View
    {
        return view('livewire.plots.project-inventory');
    }
}
