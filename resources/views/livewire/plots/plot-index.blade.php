@php use App\Enums\PlotStatus; @endphp

<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Projects', 'url' => route('projects.index')],
        ['label' => $project->name, 'url' => route('projects.show', $project)],
        ['label' => $block->name.' · Plots'],
    ]" />

    <x-ui.page-header
        :title="$block->name.' — Plots'"
        :description="$project->name.' · '.$project->code">
        <x-slot:actions>
            @can('bulkCreate', App\Models\Plot::class)
                <x-ui.button variant="secondary"
                    :href="route('plots.bulk', ['project' => $project->id, 'block' => $block->id])" wire:navigate>
                    Bulk add
                </x-ui.button>
            @endcan
            @can('create', App\Models\Plot::class)
                <x-ui.button :href="route('plots.create', ['project' => $project->id, 'block' => $block->id])" wire:navigate>
                    <x-app.icon name="plus" class="size-4" /> New plot
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @include('livewire.plots._status-counts', ['counts' => $this->counts])

    <x-ui.card :padding="false">
        {{-- Filters --}}
        <div class="flex flex-col gap-3 border-b border-(--border) p-4 sm:flex-row sm:flex-wrap sm:items-end">
            <div class="min-w-40 flex-1">
                <x-ui.input label="Search" wire:model.live.debounce.300ms="search" placeholder="Plot number…" />
            </div>
            <div class="w-full sm:w-36"><x-ui.select label="Status" wire:model.live="status" placeholder="All" :options="$statuses" /></div>
            <div class="w-full sm:w-36"><x-ui.select label="Category" wire:model.live="category" placeholder="All" :options="$categories->toArray()" /></div>
            <div class="w-full sm:w-36"><x-ui.select label="Size" wire:model.live="size" placeholder="All" :options="$sizes->toArray()" /></div>
            <div class="w-full sm:w-36"><x-ui.select label="Dimension" wire:model.live="dimension" placeholder="All" :options="$dimensions->toArray()" /></div>
            <div class="w-full sm:w-32"><x-ui.select label="Facing" wire:model.live="facing" placeholder="All" :options="$facings" /></div>
            <div class="w-full sm:w-32"><x-ui.select label="Visibility" wire:model.live="active" placeholder="All" :options="['active' => 'Active', 'inactive' => 'Archived']" /></div>
            @if ($search || $status || $category || $size || $dimension || $facing || $active)
                <x-ui.button variant="ghost" wire:click="clearFilters">Clear</x-ui.button>
            @endif
        </div>

        <div wire:loading.delay class="h-0.5 w-full animate-pulse bg-(--brand-primary)"></div>

        @if ($plots->isEmpty())
            <div class="p-6">
                <x-ui.empty-state icon="layers" title="No plots"
                    :description="$search || $status ? 'Try adjusting your filters.' : 'Add plots to this block individually or in bulk.'" />
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="bg-(--surface-muted) text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr>
                            <th class="px-4 py-3"><button wire:click="sortBy('plot_number')" class="hover:text-(--content)">Plot #{{ $sort === 'plot_number' ? ($direction === 'asc' ? ' ↑' : ' ↓') : '' }}</button></th>
                            <th class="px-4 py-3"><button wire:click="sortBy('area')" class="hover:text-(--content)">Area{{ $sort === 'area' ? ($direction === 'asc' ? ' ↑' : ' ↓') : '' }}</button></th>
                            <th class="px-4 py-3">Size</th>
                            <th class="px-4 py-3">Dimension</th>
                            <th class="px-4 py-3">Facing</th>
                            <th class="px-4 py-3"><button wire:click="sortBy('status')" class="hover:text-(--content)">Status{{ $sort === 'status' ? ($direction === 'asc' ? ' ↑' : ' ↓') : '' }}</button></th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($plots as $plot)
                            <tr wire:key="plot-{{ $plot->id }}" class="hover:bg-(--surface-muted)/50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('plots.show', ['project' => $project->id, 'block' => $block->id, 'plot' => $plot->id]) }}"
                                       wire:navigate class="font-medium text-(--content) hover:text-(--brand-primary)">{{ $plot->plot_number }}</a>
                                    @unless ($plot->is_active)<x-ui.badge variant="muted" size="sm" class="ml-1">Archived</x-ui.badge>@endunless
                                </td>
                                <td class="px-4 py-3 text-(--content-muted)">{{ $plot->areaLabel() }}</td>
                                <td class="px-4 py-3 text-(--content-muted)">{{ $plot->size?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-(--content-muted)">{{ $plot->dimension?->display_name ?? '—' }}</td>
                                <td class="px-4 py-3 text-(--content-muted)">{{ $plot->facing?->label() ?? '—' }}</td>
                                <td class="px-4 py-3"><x-ui.badge :variant="$plot->status->color()">{{ $plot->status->label() }}</x-ui.badge></td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($plot->status === PlotStatus::Available)
                                            @can('hold', $plot)
                                                <x-ui.button variant="ghost" size="sm" wire:click="startHold({{ $plot->id }})">Hold</x-ui.button>
                                            @endcan
                                        @elseif ($plot->status === PlotStatus::Hold)
                                            @can('release', $plot)
                                                <x-ui.button variant="ghost" size="sm" wire:click="release({{ $plot->id }})"
                                                    wire:confirm="Release the hold on plot {{ $plot->plot_number }}?">Release</x-ui.button>
                                            @endcan
                                        @endif
                                        @can('update', $plot)
                                            <x-ui.button variant="ghost" size="sm"
                                                :href="route('plots.edit', ['project' => $project->id, 'block' => $block->id, 'plot' => $plot->id])"
                                                wire:navigate>Edit</x-ui.button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $plots->onEachSide(1)->links() }}</div>
        @endif
    </x-ui.card>

    {{-- Hold dialog --}}
    @if ($holdingPlotId !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" wire:key="hold-dialog">
            <div class="absolute inset-0 bg-slate-900/50" wire:click="cancelHold"></div>
            <div class="relative w-full max-w-md rounded-xl border border-(--border) bg-(--surface) shadow-xl">
                <div class="border-b border-(--border) px-5 py-4">
                    <h3 class="text-sm font-semibold">Place plot on hold</h3>
                </div>
                <form wire:submit="confirmHold" class="space-y-4 px-5 py-4">
                    <x-ui.input type="datetime-local" label="Hold expires at" wire:model="holdExpiresAt"
                        :error="$errors->first('holdExpiresAt')" hint="Leave blank for an open-ended hold." />
                    <x-ui.textarea label="Reason" wire:model="holdReason" rows="2" :error="$errors->first('holdReason')" />
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="secondary" wire:click="cancelHold">Cancel</x-ui.button>
                        <x-ui.button type="submit">Place on hold</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
