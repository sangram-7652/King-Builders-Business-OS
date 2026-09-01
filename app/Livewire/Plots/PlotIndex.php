<?php

declare(strict_types=1);

namespace App\Livewire\Plots;

use App\Actions\Plots\HoldPlotAction;
use App\Actions\Plots\ReleasePlotHoldAction;
use App\Actions\Plots\TogglePlotActive;
use App\Enums\PlotFacing;
use App\Enums\PlotStatus;
use App\Exceptions\DomainException;
use App\Models\Block;
use App\Models\Masters\PlotCategory;
use App\Models\Masters\PlotDimension;
use App\Models\Masters\PlotSize;
use App\Models\Plot;
use App\Models\Project;
use App\Support\Plots\PlotStatusCounts;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class PlotIndex extends Component
{
    use WithPagination;

    public Project $project;

    public Block $block;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $size = '';

    #[Url]
    public string $dimension = '';

    #[Url]
    public string $facing = '';

    #[Url]
    public string $active = '';

    #[Url]
    public string $sort = 'plot_number';

    #[Url]
    public string $direction = 'asc';

    #[Url]
    public int $perPage = 20;

    // Hold dialog
    public ?int $holdingPlotId = null;

    public string $holdReason = '';

    public string $holdExpiresAt = '';

    public function mount(Project $project, Block $block): void
    {
        $this->authorize('viewAny', Plot::class);
        $this->project = $project;
        $this->block = $block;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'category', 'size', 'dimension', 'facing', 'active', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function sortBy(string $column): void
    {
        $this->direction = $this->sort === $column && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'category', 'size', 'dimension', 'facing', 'active');
        $this->resetPage();
    }

    #[Computed]
    public function counts(): PlotStatusCounts
    {
        return PlotStatusCounts::for(Plot::query()->where('block_id', $this->block->id));
    }

    // --- Lifecycle actions ------------------------------------------------

    public function startHold(int $plot): void
    {
        $model = $this->plotOrFail($plot);
        $this->authorize('hold', $model);

        $this->holdingPlotId = $model->id;
        $this->holdReason = '';
        $this->holdExpiresAt = now()->addDay()->format('Y-m-d\TH:i');
    }

    public function cancelHold(): void
    {
        $this->reset('holdingPlotId', 'holdReason', 'holdExpiresAt');
    }

    public function confirmHold(): void
    {
        $model = $this->plotOrFail((int) $this->holdingPlotId);
        $this->authorize('hold', $model);

        $this->validate([
            'holdReason' => ['nullable', 'string', 'max:255'],
            'holdExpiresAt' => ['nullable', 'date', 'after:now'],
        ]);

        try {
            app(HoldPlotAction::class)->handle(
                plotId: $model->id,
                heldByUserId: auth()->id(),
                expiresAt: $this->holdExpiresAt !== '' ? Carbon::parse($this->holdExpiresAt) : null,
                reason: $this->holdReason ?: null,
            );
            $this->dispatch('toast', message: 'Plot placed on hold.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }

        $this->cancelHold();
        unset($this->counts);
    }

    public function release(int $plot): void
    {
        $model = $this->plotOrFail($plot);
        $this->authorize('release', $model);

        try {
            app(ReleasePlotHoldAction::class)->handle($model->id);
            $this->dispatch('toast', message: 'Hold released.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }

        unset($this->counts);
    }

    public function toggleActive(int $plot): void
    {
        $model = $this->plotOrFail($plot);
        $this->authorize($model->is_active ? 'archive' : 'activate', $model);

        try {
            $updated = app(TogglePlotActive::class)->handle($model);
            $this->dispatch('toast', message: $updated->is_active ? 'Plot activated.' : 'Plot archived.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }

        unset($this->counts);
    }

    private function plotOrFail(int $id): Plot
    {
        return $this->block->plots()->findOrFail($id);
    }

    /**
     * @return Builder<Plot>
     */
    protected function query(): Builder
    {
        $sortable = ['plot_number', 'area', 'status', 'created_at'];
        $sort = in_array($this->sort, $sortable, true) ? $this->sort : 'plot_number';
        $direction = $this->direction === 'desc' ? 'desc' : 'asc';

        return Plot::query()
            ->where('block_id', $this->block->id)
            ->with(['size:id,name', 'dimension:id,display_name', 'category:id,name'])
            ->search($this->search)
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->category !== '', fn (Builder $q) => $q->where('plot_category_id', $this->category))
            ->when($this->size !== '', fn (Builder $q) => $q->where('plot_size_id', $this->size))
            ->when($this->dimension !== '', fn (Builder $q) => $q->where('plot_dimension_id', $this->dimension))
            ->when($this->facing !== '', fn (Builder $q) => $q->where('facing', $this->facing))
            ->when($this->active === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($this->active === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->orderBy($sort, $direction)
            ->orderBy('id');
    }

    public function render(): View
    {
        return view('livewire.plots.plot-index', [
            'plots' => $this->query()->paginate($this->perPage),
            'statuses' => PlotStatus::options(),
            'facings' => PlotFacing::options(),
            'categories' => PlotCategory::query()->orderBy('name')->pluck('name', 'id'),
            'sizes' => PlotSize::query()->orderBy('name')->pluck('name', 'id'),
            'dimensions' => PlotDimension::query()->orderBy('display_name')->pluck('display_name', 'id'),
        ])->title("{$this->block->name} · Plots");
    }
}
