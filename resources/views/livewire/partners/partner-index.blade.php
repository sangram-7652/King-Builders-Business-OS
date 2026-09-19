<div class="space-y-6">
    <x-ui.page-header title="Promoters"
        description="Promoters who source bookings and earn a flat commission %. Booking attribution builds on this master.">
        <x-slot:actions>
            @can('create', App\Models\Partner::class)
                <x-ui.button :href="route('partners.create')" wire:navigate>
                    <x-app.icon name="plus" class="size-4" /> New promoter
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :padding="false">
        <div class="flex flex-col gap-3 border-b border-(--border) p-4 sm:flex-row sm:flex-wrap sm:items-end">
            <div class="min-w-44 flex-1">
                <x-ui.input label="Search" wire:model.live.debounce.300ms="search" placeholder="Code, name, company, phone…" />
            </div>
            <div class="w-full sm:w-44"><x-ui.select label="Status" wire:model.live="status" placeholder="All" :options="$statuses" /></div>
            <div class="w-full sm:w-44"><x-ui.select label="Type" wire:model.live="type" placeholder="All" :options="$types" /></div>
            @if ($search || $status || $type)
                <x-ui.button variant="ghost" wire:click="clearFilters">Clear</x-ui.button>
            @endif
        </div>

        <div wire:loading.delay class="h-0.5 w-full animate-pulse bg-(--brand-primary)"></div>

        @if ($partners->isEmpty())
            <div class="p-6">
                <x-ui.empty-state icon="user" title="No partners found"
                    :description="$search || $status || $type ? 'Try adjusting your filters.' : 'Add the first channel partner to get started.'" />
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="bg-(--surface-muted) text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr>
                            <th class="px-4 py-3"><button wire:click="sortBy('partner_code')" class="hover:text-(--content)">Partner{{ $sort === 'partner_code' ? ($direction === 'asc' ? ' ↑' : ' ↓') : '' }}</button></th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Contact</th>
                            <th class="px-4 py-3">Projects</th>
                            <th class="px-4 py-3"><button wire:click="sortBy('status')" class="hover:text-(--content)">Status{{ $sort === 'status' ? ($direction === 'asc' ? ' ↑' : ' ↓') : '' }}</button></th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($partners as $partner)
                            <tr wire:key="partner-{{ $partner->id }}" class="hover:bg-(--surface-muted)/50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('partners.show', $partner) }}" wire:navigate class="font-medium text-(--content) hover:text-(--brand-primary)">{{ $partner->displayName() }}</a>
                                    <div class="text-xs text-(--content-muted)">{{ $partner->partner_code }}</div>
                                </td>
                                <td class="px-4 py-3"><x-ui.badge :variant="$partner->type->color()" size="sm">{{ $partner->type->label() }}</x-ui.badge></td>
                                <td class="px-4 py-3 text-(--content-muted)">
                                    <div>{{ $partner->phone }}</div>
                                    @if ($partner->email)<div class="text-xs">{{ $partner->email }}</div>@endif
                                </td>
                                <td class="px-4 py-3 text-(--content-muted)">{{ $partner->active_project_authorizations_count }}</td>
                                <td class="px-4 py-3"><x-ui.badge :variant="$partner->status->color()">{{ $partner->status->label() }}</x-ui.badge></td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <x-ui.button variant="ghost" size="sm" :href="route('partners.show', $partner)" wire:navigate>View</x-ui.button>
                                        @can('update', $partner)
                                            <x-ui.button variant="ghost" size="sm" :href="route('partners.edit', $partner)" wire:navigate>Edit</x-ui.button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-(--border) p-4">{{ $partners->links() }}</div>
        @endif
    </x-ui.card>
</div>
