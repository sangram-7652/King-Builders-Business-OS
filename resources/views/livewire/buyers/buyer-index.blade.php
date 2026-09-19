@php use App\Enums\BuyerStatus; @endphp

<div class="space-y-6">
    <x-ui.page-header title="Buyers" description="Customers, created directly.">
        <x-slot:actions>
            @can('create', App\Models\Buyer::class)
                <x-ui.button :href="route('buyers.create')" wire:navigate>
                    <x-app.icon name="plus" class="size-4" /> New buyer
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :padding="false">
        <div class="flex flex-col gap-3 border-b border-(--border) p-4 sm:flex-row sm:items-end">
            <div class="min-w-48 flex-1">
                <x-ui.input label="Search" wire:model.live.debounce.300ms="search" placeholder="Code, name, phone or email…" />
            </div>
            <div class="w-full sm:w-40"><x-ui.select label="Status" wire:model.live="status" placeholder="All" :options="$statuses" /></div>
            @if ($search || $status)
                <x-ui.button variant="ghost" wire:click="clearFilters">Clear</x-ui.button>
            @endif
        </div>

        <div wire:loading.delay class="h-0.5 w-full animate-pulse bg-(--brand-primary)"></div>

        @if ($buyers->isEmpty())
            <div class="p-6"><x-ui.empty-state icon="user" title="No buyers found" /></div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="bg-(--surface-muted) text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr>
                            <th class="px-4 py-3"><button wire:click="sortBy('customer_code')" class="hover:text-(--content)">Code{{ $sort === 'customer_code' ? ($direction === 'asc' ? ' ↑' : ' ↓') : '' }}</button></th>
                            <th class="px-4 py-3"><button wire:click="sortBy('first_name')" class="hover:text-(--content)">Name{{ $sort === 'first_name' ? ($direction === 'asc' ? ' ↑' : ' ↓') : '' }}</button></th>
                            <th class="px-4 py-3">Phone</th>
                            <th class="px-4 py-3">Email</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($buyers as $buyer)
                            <tr wire:key="buyer-{{ $buyer->id }}" class="hover:bg-(--surface-muted)/50">
                                <td class="px-4 py-3 font-mono text-xs text-(--content-muted)">{{ $buyer->customer_code }}</td>
                                <td class="px-4 py-3">
                                    <a href="{{ route('buyers.show', $buyer) }}" wire:navigate class="font-medium text-(--content) hover:text-(--brand-primary)">{{ $buyer->fullName() }}</a>
                                </td>
                                <td class="px-4 py-3 text-(--content-muted)">{{ $buyer->phone }}</td>
                                <td class="px-4 py-3 text-(--content-muted)">{{ $buyer->email ?: '—' }}</td>
                                <td class="px-4 py-3"><x-ui.badge :variant="$buyer->status->color()">{{ $buyer->status->label() }}</x-ui.badge></td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <x-ui.button variant="ghost" size="sm" :href="route('buyers.show', $buyer)" wire:navigate>View</x-ui.button>
                                        @can('update', $buyer)
                                            <x-ui.button variant="ghost" size="sm" :href="route('buyers.edit', $buyer)" wire:navigate>Edit</x-ui.button>
                                        @endcan
                                        @can('changeStatus', $buyer)
                                            @if ($buyer->status !== BuyerStatus::Archived)
                                                <x-ui.button variant="ghost" size="sm"
                                                    wire:click="setStatus({{ $buyer->id }}, 'archived')"
                                                    wire:confirm="Archive {{ $buyer->fullName() }}?">Archive</x-ui.button>
                                            @else
                                                <x-ui.button variant="ghost" size="sm" wire:click="setStatus({{ $buyer->id }}, 'active')">Restore</x-ui.button>
                                            @endif
                                        @endcan
                                        @can('delete', $buyer)
                                            <x-ui.button variant="ghost" size="sm" class="text-red-600"
                                                wire:click="delete({{ $buyer->id }})" wire:confirm="Delete this buyer?">Delete</x-ui.button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $buyers->onEachSide(1)->links() }}</div>
        @endif
    </x-ui.card>
</div>
