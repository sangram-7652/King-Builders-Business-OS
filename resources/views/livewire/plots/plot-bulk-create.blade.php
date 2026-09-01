<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Projects', 'url' => route('projects.index')],
        ['label' => $project->name, 'url' => route('projects.show', $project)],
        ['label' => $block->name, 'url' => route('plots.index', ['project' => $project->id, 'block' => $block->id])],
        ['label' => 'Bulk add plots'],
    ]" />

    <x-ui.page-header title="Bulk add plots" :description="$project->name.' · '.$block->name" />

    <form wire:submit="create" class="space-y-6">
        <x-ui.card title="Number range" subtitle="Generates one plot per number in the range, all in a single transaction.">
            <div class="grid gap-5 sm:grid-cols-4">
                <x-ui.input label="Prefix" wire:model="prefix" placeholder="(none)" :error="$errors->first('prefix')" hint="Optional, e.g. A-" />
                <x-ui.input type="number" label="From" wire:model.live="from" required :error="$errors->first('from')" />
                <x-ui.input type="number" label="To" wire:model.live="to" required :error="$errors->first('to')" />
                <x-ui.input type="number" label="Zero-pad width" wire:model="pad" :error="$errors->first('pad')" hint="0 = no padding" />
            </div>
            @if ($this->previewCount() > 0)
                <p class="mt-3 text-sm text-(--content-muted)">
                    Will create <span class="font-semibold text-(--content)">{{ $this->previewCount() }}</span> plots
                    (<span class="font-mono">{{ $prefix }}{{ $pad > 0 ? str_pad((string) $from, $pad, '0', STR_PAD_LEFT) : $from }}</span>
                    … <span class="font-mono">{{ $prefix }}{{ $pad > 0 ? str_pad((string) $to, $pad, '0', STR_PAD_LEFT) : $to }}</span>).
                </p>
            @endif
        </x-ui.card>

        <x-ui.card title="Applied to every plot">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.select label="Category" wire:model="plot_category_id" placeholder="—" :options="$categories->toArray()" :error="$errors->first('plot_category_id')" />
                <x-ui.select label="Size" wire:model.live="plot_size_id" placeholder="—" :options="$sizes->toArray()" :error="$errors->first('plot_size_id')" hint="Prefills the area." />
                <x-ui.select label="Dimension" wire:model="plot_dimension_id" placeholder="—" :options="$dimensions->toArray()" :error="$errors->first('plot_dimension_id')" />
                <x-ui.select label="Facing" wire:model="facing" placeholder="—" :options="$facings" :error="$errors->first('facing')" />
                <x-ui.input type="number" step="0.01" label="Area" wire:model="area" required :error="$errors->first('area')" />
                <x-ui.select label="Area unit" wire:model="area_unit" :options="$areaUnits" :error="$errors->first('area_unit')" />
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary" :href="route('plots.index', ['project' => $project->id, 'block' => $block->id])" wire:navigate>Cancel</x-ui.button>
            <x-ui.button type="submit" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="create">Create plots</span>
                <span wire:loading wire:target="create">Creating…</span>
            </x-ui.button>
        </div>
    </form>
</div>
