@php use App\Support\Plots\PlotRoutes; @endphp
<div class="space-y-6">
    @unless ($embedded)
        <x-ui.breadcrumb :items="[
            ['label' => 'Projects', 'url' => route('projects.index')],
            ['label' => $project->name, 'url' => route('projects.show', $project)],
            ...($block ? [['label' => $block->name, 'url' => route('plots.index', ['project' => $project->id, 'block' => $block->id])]] : [['label' => 'Direct Plots', 'url' => route('plots.direct.index', ['project' => $project->id])]]),
            ['label' => $this->editing ? 'Edit plot '.$plot->plot_number : 'New plot'],
        ]" />

        <x-ui.page-header
            :title="$this->editing ? 'Edit plot '.$plot->plot_number : 'New plot'"
            :description="$project->name.' · '.($block?->name ?? 'Direct Project Plot')" />
    @endunless

    <form wire:submit="save" class="space-y-6">
        <x-ui.card title="Identity">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input label="Plot number" wire:model="plot_number" required
                    :error="$errors->first('plot_number')" hint="Unique within the block (or within the project, for a direct plot), e.g. 101 or A-12." />
                @if ($this->editing)
                    <x-ui.select label="Block" wire:model="block_id" placeholder="None / Direct Project Plot"
                        :options="$blocks->toArray()" :error="$errors->first('block_id')"
                        hint="Optional — leave blank for a plot directly under the project, with no block." />
                @else
                    <div>
                        <p class="text-sm font-medium text-(--content)">Block</p>
                        <p class="mt-2 text-sm text-(--content-muted)">{{ $block?->name ?? 'None — direct project plot' }}</p>
                        @error('block_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>
        </x-ui.card>

        <x-ui.card title="Specification" subtitle="Area is stored on the plot — later changes to a Plot Size master won't rewrite it.">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.select label="Category" wire:model="plot_category_id" placeholder="—" :options="$categories->toArray()" :error="$errors->first('plot_category_id')" />
                <x-ui.select label="Size" wire:model.live="plot_size_id" placeholder="—" :options="$sizes->toArray()" :error="$errors->first('plot_size_id')" hint="Picking a size prefills the area below." />
                <x-ui.select label="Dimension" wire:model="plot_dimension_id" placeholder="—" :options="$dimensions->toArray()" :error="$errors->first('plot_dimension_id')" hint="Width/Length — printed as Front/Depth on the Plot KYC Receipt." />
                <x-ui.select label="Facing" wire:model="facing" placeholder="—" :options="$facings" :error="$errors->first('facing')" />
                <x-ui.input type="number" step="0.01" label="Area" wire:model="area" required :error="$errors->first('area')" />
                <x-ui.select label="Area unit" wire:model="area_unit" :options="$areaUnits" :error="$errors->first('area_unit')" />
            </div>
        </x-ui.card>

        <x-ui.card title="Land records" subtitle="Optional — used on the Plot KYC / Registry KYC Receipt.">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input label="Village name" wire:model="village_name" :error="$errors->first('village_name')" />
                <x-ui.input label="Gata No." wire:model="gata_number" :error="$errors->first('gata_number')" />
            </div>
        </x-ui.card>

        <x-ui.card title="Plot boundary (Chauhaddi)" subtitle="Optional — the four-side boundary description for the Plot KYC Receipt.">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input label="East" wire:model="boundary_east" :error="$errors->first('boundary_east')" />
                <x-ui.input label="West" wire:model="boundary_west" :error="$errors->first('boundary_west')" />
                <x-ui.input label="North" wire:model="boundary_north" :error="$errors->first('boundary_north')" />
                <x-ui.input label="South" wire:model="boundary_south" :error="$errors->first('boundary_south')" />
            </div>
        </x-ui.card>

        @unless ($this->editing)
            <x-ui.card>
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="is_active" class="rounded border-(--border)"> Active
                </label>
            </x-ui.card>
        @endunless

        <div class="flex justify-end gap-2">
            @php
                $cancelRoute = $this->editing
                    ? PlotRoutes::forPlot($plot, 'show')
                    : ['name' => PlotRoutes::name('index', $block), 'params' => PlotRoutes::params($project, $block)];
            @endphp
            @unless ($embedded)
                <x-ui.button variant="secondary" :href="route($cancelRoute['name'], $cancelRoute['params'])" wire:navigate>Cancel</x-ui.button>
            @endunless
            <x-ui.button type="submit" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">{{ $this->editing ? 'Save changes' : 'Create plot' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
            </x-ui.button>
        </div>
    </form>
</div>
