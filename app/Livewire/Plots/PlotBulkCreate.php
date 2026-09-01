<?php

declare(strict_types=1);

namespace App\Livewire\Plots;

use App\Actions\Plots\BulkCreatePlots;
use App\Enums\Masters\AreaUnit;
use App\Enums\PlotFacing;
use App\Exceptions\DomainException;
use App\Models\Block;
use App\Models\Masters\PlotCategory;
use App\Models\Masters\PlotDimension;
use App\Models\Masters\PlotSize;
use App\Models\Plot;
use App\Models\Project;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class PlotBulkCreate extends Component
{
    public Project $project;

    public Block $block;

    public string $prefix = '';

    public ?int $from = null;

    public ?int $to = null;

    public int $pad = 0;

    public string $plot_category_id = '';

    public string $plot_size_id = '';

    public string $plot_dimension_id = '';

    public string $area = '';

    public string $area_unit = 'sq_ft';

    public string $facing = '';

    public bool $is_active = true;

    public function mount(Project $project, Block $block): void
    {
        $this->authorize('bulkCreate', Plot::class);
        $this->project = $project;
        $this->block = $block;
    }

    public function updatedPlotSizeId(mixed $value): void
    {
        if ($value === '' || $this->area !== '') {
            return;
        }

        $size = PlotSize::find($value);

        if ($size !== null) {
            $this->area = (string) $size->area;
            $this->area_unit = $size->unit->value;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'prefix' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9\/-]*$/'],
            'from' => ['required', 'integer', 'min:1', 'max:999999'],
            'to' => ['required', 'integer', 'min:1', 'max:999999', 'gte:from'],
            'pad' => ['integer', 'min:0', 'max:6'],
            'plot_category_id' => ['nullable', Rule::exists('plot_categories', 'id')->withoutTrashed()],
            'plot_size_id' => ['nullable', Rule::exists('plot_sizes', 'id')->withoutTrashed()],
            'plot_dimension_id' => ['nullable', Rule::exists('plot_dimensions', 'id')->withoutTrashed()],
            'area' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'area_unit' => ['required', new Enum(AreaUnit::class)],
            'facing' => ['nullable', new Enum(PlotFacing::class)],
            'is_active' => ['boolean'],
        ];
    }

    #[Computed]
    public function previewCount(): int
    {
        if ($this->from === null || $this->to === null || $this->to < $this->from) {
            return 0;
        }

        return $this->to - $this->from + 1;
    }

    public function create()
    {
        $data = $this->validate();

        if ($this->previewCount() > BulkCreatePlots::MAX_PER_RUN) {
            $this->addError('to', 'A single run is limited to '.BulkCreatePlots::MAX_PER_RUN.' plots.');

            return;
        }

        try {
            $count = app(BulkCreatePlots::class)->handle($this->project, $this->block, $data);
        } catch (DomainException $e) {
            $this->addError('from', $e->getMessage());

            return;
        }

        $this->dispatch('toast', message: "{$count} plots created.", variant: 'success');

        $this->redirectRoute('plots.index', [
            'project' => $this->project->id,
            'block' => $this->block->id,
        ], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.plots.plot-bulk-create', [
            'categories' => PlotCategory::query()->active()->ordered()->pluck('name', 'id'),
            'sizes' => PlotSize::query()->active()->ordered()->pluck('name', 'id'),
            'dimensions' => PlotDimension::query()->active()->ordered()->pluck('display_name', 'id'),
            'areaUnits' => AreaUnit::options(),
            'facings' => PlotFacing::options(),
        ])->title("Bulk add plots · {$this->block->name}");
    }
}
