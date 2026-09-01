<?php

declare(strict_types=1);

namespace App\Livewire\Plots;

use App\Models\Plot;
use App\Models\Project;
use App\Support\Plots\PlotStatusCounts;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Project-wide plot inventory summary — nested in the project detail
 * "Inventory" tab. All counts are live DB aggregates.
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
     * @return Collection<int, object>
     */
    #[Computed]
    public function blocks()
    {
        return $this->project->blocks()
            ->orderBy('sort_order')->orderBy('name')
            ->withCount([
                'plots as plots_total',
                'plots as plots_available' => fn ($q) => $q->where('status', 'available'),
                'plots as plots_hold' => fn ($q) => $q->where('status', 'hold'),
                'plots as plots_booked' => fn ($q) => $q->where('status', 'booked'),
                'plots as plots_sold' => fn ($q) => $q->where('status', 'sold'),
            ])
            ->get();
    }

    public function render(): View
    {
        return view('livewire.plots.project-inventory');
    }
}
