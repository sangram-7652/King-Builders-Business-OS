<div class="space-y-6">
    <x-ui.page-header title="Commission schemes"
        description="Versioned rulebooks for channel-partner commission. Published versions are frozen — changing the numbers means a new version.">
        <x-slot:actions>
            @can('create', App\Models\CommissionScheme::class)
                <x-ui.button :href="route('commission-schemes.create')" wire:navigate>
                    <x-app.icon name="plus" class="size-4" /> New scheme
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :padding="false">
        <div class="flex flex-col gap-3 border-b border-(--border) p-4 sm:flex-row sm:items-end">
            <div class="min-w-44 flex-1">
                <x-ui.input label="Search" wire:model.live.debounce.300ms="search" placeholder="Name or code…" />
            </div>
            <div class="w-full sm:w-44"><x-ui.select label="Status" wire:model.live="status" placeholder="All" :options="$statuses" /></div>
        </div>

        @if ($schemes->isEmpty())
            <div class="p-6"><x-ui.empty-state icon="inbox" title="No commission schemes yet"
                description="Create a scheme, add its rule, then publish it." /></div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="bg-(--surface-muted) text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr>
                            <th class="px-4 py-3">Scheme</th>
                            <th class="px-4 py-3">Version</th>
                            <th class="px-4 py-3">Basis</th>
                            <th class="px-4 py-3">Applies to</th>
                            <th class="px-4 py-3">Rules</th>
                            <th class="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($schemes as $scheme)
                            <tr wire:key="scheme-{{ $scheme->id }}" class="hover:bg-(--surface-muted)/50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('commission-schemes.show', $scheme) }}" wire:navigate class="font-medium text-(--content) hover:text-(--brand-primary)">{{ $scheme->name }}</a>
                                    <div class="text-xs text-(--content-muted)">{{ $scheme->code }}</div>
                                </td>
                                <td class="px-4 py-3 tabular-nums">v{{ $scheme->version }}</td>
                                <td class="px-4 py-3 text-(--content-muted)">{{ $scheme->basis->shortLabel() }}</td>
                                <td class="px-4 py-3 text-(--content-muted)">
                                    {{ $scheme->partner_type?->label() ?? ($scheme->is_default ? 'Default (all)' : 'Assigned only') }}
                                </td>
                                <td class="px-4 py-3 tabular-nums">{{ $scheme->rules_count }}</td>
                                <td class="px-4 py-3"><x-ui.badge :variant="$scheme->status->color()">{{ $scheme->status->label() }}</x-ui.badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-(--border) p-4">{{ $schemes->links() }}</div>
        @endif
    </x-ui.card>
</div>
