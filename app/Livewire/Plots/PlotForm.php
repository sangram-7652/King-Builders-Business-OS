<?php

declare(strict_types=1);

namespace App\Livewire\Plots;

use App\Actions\Plots\CreatePlot;
use App\Actions\Plots\UpdatePlot;
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
class PlotForm extends Component
{
    public Project $project;

    public Block $block;

    public ?Plot $plot = null;

    public string $plot_number = '';

    public string $plot_category_id = '';

    public string $plot_size_id = '';

    public string $plot_dimension_id = '';

    public string $area = '';

    public string $area_unit = 'sq_ft';

    public string $facing = '';

    public string $village_name = '';

    public string $gata_number = '';

    public string $boundary_east = '';

    public string $boundary_west = '';

    public string $boundary_north = '';

    public string $boundary_south = '';

    public bool $is_active = true;

    public function mount(Project $project, Block $block, ?Plot $plot = null): void
    {
        $this->project = $project;
        $this->block = $block;

        if ($plot?->exists) {
            $this->authorize('update', $plot);
            $this->plot = $plot;
            $this->plot_number = $plot->plot_number;
            $this->plot_category_id = (string) ($plot->plot_category_id ?? '');
            $this->plot_size_id = (string) ($plot->plot_size_id ?? '');
            $this->plot_dimension_id = (string) ($plot->plot_dimension_id ?? '');
            $this->area = (string) $plot->area;
            $this->area_unit = $plot->area_unit->value;
            $this->facing = $plot->facing?->value ?? '';
            $this->village_name = $plot->village_name ?? '';
            $this->gata_number = $plot->gata_number ?? '';
            $this->boundary_east = $plot->boundary_east ?? '';
            $this->boundary_west = $plot->boundary_west ?? '';
            $this->boundary_north = $plot->boundary_north ?? '';
            $this->boundary_south = $plot->boundary_south ?? '';
            $this->is_active = $plot->is_active;
        } else {
            $this->authorize('create', Plot::class);
        }
    }

    #[Computed]
    public function editing(): bool
    {
        return $this->plot !== null;
    }

    /**
     * When a size is picked and the area is empty, prefill the snapshot from
     * that master. The value stays editable and is then stored on the plot.
     */
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
            'plot_number' => [
                'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9\/-]+$/',
                Rule::unique('plots', 'plot_number')
                    ->where('project_id', $this->project->id)
                    ->where('block_id', $this->block->id)
                    ->ignore($this->plot?->id)
                    ->withoutTrashed(),
            ],
            'plot_category_id' => ['nullable', Rule::exists('plot_categories', 'id')->withoutTrashed()],
            'plot_size_id' => ['nullable', Rule::exists('plot_sizes', 'id')->withoutTrashed()],
            'plot_dimension_id' => ['nullable', Rule::exists('plot_dimensions', 'id')->withoutTrashed()],
            'area' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'area_unit' => ['required', new Enum(AreaUnit::class)],
            'facing' => ['nullable', new Enum(PlotFacing::class)],
            'village_name' => ['nullable', 'string', 'max:255'],
            'gata_number' => ['nullable', 'string', 'max:64'],
            'boundary_east' => ['nullable', 'string', 'max:255'],
            'boundary_west' => ['nullable', 'string', 'max:255'],
            'boundary_north' => ['nullable', 'string', 'max:255'],
            'boundary_south' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'plot_number.unique' => 'This plot number already exists in this block.',
            'plot_number.regex' => 'Plot number may only contain letters, numbers, / and -.',
        ];
    }

    public function save()
    {
        $data = $this->validate();

        try {
            $plot = $this->editing
                ? app(UpdatePlot::class)->handle($this->plot, $data)
                : app(CreatePlot::class)->handle($this->project, $this->block, $data);
        } catch (DomainException $e) {
            $this->addError('plot_number', $e->getMessage());

            return;
        }

        $this->dispatch('toast', message: $this->editing ? 'Plot updated.' : 'Plot created.', variant: 'success');

        $this->redirectRoute('plots.show', [
            'project' => $this->project->id,
            'block' => $this->block->id,
            'plot' => $plot->id,
        ], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.plots.plot-form', [
            'categories' => PlotCategory::query()->active()->ordered()->pluck('name', 'id'),
            'sizes' => PlotSize::query()->active()->ordered()->pluck('name', 'id'),
            'dimensions' => PlotDimension::query()->active()->ordered()->pluck('display_name', 'id'),
            'areaUnits' => AreaUnit::options(),
            'facings' => PlotFacing::options(),
        ])->title(($this->editing ? 'Edit plot ' : 'New plot').' · '.$this->block->name);
    }
}
