<div class="space-y-6" wire:poll.30s>
    @include('livewire.plots._status-counts', ['counts' => $this->counts])

    @if ($this->plots->isEmpty() && $this->blockGroups->isEmpty())
        <x-ui.card>
            <x-ui.empty-state
                icon="layers"
                title="No plots yet"
                description="Create plots directly under the project in the Create Plots tab, or add a Block in the Blocks tab and create plots inside it." />
        </x-ui.card>
    @endif

    {{-- Block-based plots, one section per Block. --}}
    @foreach ($this->blockGroups as $group)
        @php $block = $group['block']; @endphp
        <x-ui.card :padding="false" wire:key="inv-block-{{ $block->id }}"
            :title="$block->name"
            :subtitle="$group['plots']->count().' plot(s)'.($block->is_active ? '' : ' · Inactive block')">
            <x-slot:actions>
                <x-ui.button variant="ghost" size="sm"
                    :href="route('plots.index', ['project' => $project->id, 'block' => $block->id])"
                    wire:navigate>Open block →</x-ui.button>
            </x-slot:actions>

            @if ($group['plots']->isEmpty())
                <p class="px-4 py-4 text-sm text-(--content-muted)">No plots in this block yet.</p>
            @else
                @include('livewire.plots._inventory-plot-table', ['plots' => $group['plots'], 'keyPrefix' => 'inv-block-plot-'])
            @endif
        </x-ui.card>
    @endforeach

    {{-- Direct project plots (no Block) — hidden entirely when there are none. --}}
    @if ($this->directPlots->isNotEmpty())
        <x-ui.card :padding="false" title="Direct Project Plots" :subtitle="$this->directPlots->count().' plot(s) with no Block'">
            <x-slot:actions>
                <x-ui.button variant="ghost" size="sm" :href="route('plots.direct.index', ['project' => $project->id])" wire:navigate>
                    Open →
                </x-ui.button>
            </x-slot:actions>

            @include('livewire.plots._inventory-plot-table', ['plots' => $this->directPlots, 'keyPrefix' => 'inv-direct-'])
        </x-ui.card>
    @endif
</div>
