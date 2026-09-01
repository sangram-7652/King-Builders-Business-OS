<div class="space-y-6" wire:poll.30s>
    @include('livewire.plots._status-counts', ['counts' => $this->counts])

    @if ($this->blocks->isEmpty())
        <x-ui.empty-state
            icon="layers"
            title="No blocks yet"
            description="Add blocks in the Blocks tab, then create plots inside them." />
    @else
        <x-ui.card :padding="false" title="By block">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="bg-(--surface-muted) text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr>
                            <th class="px-4 py-3">Block</th>
                            <th class="px-4 py-3 text-right">Total</th>
                            <th class="px-4 py-3 text-right">Available</th>
                            <th class="px-4 py-3 text-right">Hold</th>
                            <th class="px-4 py-3 text-right">Booked</th>
                            <th class="px-4 py-3 text-right">Sold</th>
                            <th class="px-4 py-3 text-right">Plots</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($this->blocks as $block)
                            <tr wire:key="inv-block-{{ $block->id }}" class="hover:bg-(--surface-muted)/50">
                                <td class="px-4 py-3">
                                    <span class="font-medium text-(--content)">{{ $block->name }}</span>
                                    @unless ($block->is_active)
                                        <x-ui.badge variant="muted" size="sm" class="ml-1">Inactive</x-ui.badge>
                                    @endunless
                                </td>
                                <td class="px-4 py-3 text-right text-(--content)">{{ $block->plots_total }}</td>
                                <td class="px-4 py-3 text-right text-(--content-muted)">{{ $block->plots_available }}</td>
                                <td class="px-4 py-3 text-right text-(--content-muted)">{{ $block->plots_hold }}</td>
                                <td class="px-4 py-3 text-right text-(--content-muted)">{{ $block->plots_booked }}</td>
                                <td class="px-4 py-3 text-right text-(--content-muted)">{{ $block->plots_sold }}</td>
                                <td class="px-4 py-3 text-right">
                                    <x-ui.button variant="ghost" size="sm"
                                        :href="route('plots.index', ['project' => $project->id, 'block' => $block->id])"
                                        wire:navigate>Open →</x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif
</div>
