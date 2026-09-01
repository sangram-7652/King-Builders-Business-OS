@php use App\Enums\LeadStatus; @endphp

<div class="space-y-6">
    <x-ui.page-header title="Leads" description="Enquiries in the sales pipeline. Qualified leads convert into buyers.">
        <x-slot:actions>
            @can('create', App\Models\Lead::class)
                <x-ui.button :href="route('leads.create')" wire:navigate>
                    <x-app.icon name="plus" class="size-4" /> New lead
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :padding="false">
        <div class="flex flex-col gap-3 border-b border-(--border) p-4 sm:flex-row sm:flex-wrap sm:items-end">
            <div class="min-w-44 flex-1">
                <x-ui.input label="Search" wire:model.live.debounce.300ms="search" placeholder="Name, phone or email…" />
            </div>
            <div class="w-full sm:w-36"><x-ui.select label="Status" wire:model.live="status" placeholder="All" :options="$statuses" /></div>
            <div class="w-full sm:w-40"><x-ui.select label="Source" wire:model.live="source" placeholder="All" :options="$sources->toArray()" /></div>
            <div class="w-full sm:w-40">
                <x-ui.select label="Assigned" wire:model.live="assigned" placeholder="Anyone">
                    <option value="me">Me</option>
                    <option value="unassigned">Unassigned</option>
                    @foreach ($assignees as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <div class="w-full sm:w-36">
                <x-ui.select label="Follow-up" wire:model.live="followUp" placeholder="Any"
                    :options="['overdue' => 'Overdue', 'today' => 'Today', 'upcoming' => 'Upcoming', 'none' => 'None']" />
            </div>
            @if ($search || $status || $source || $assigned || $followUp)
                <x-ui.button variant="ghost" wire:click="clearFilters">Clear</x-ui.button>
            @endif
        </div>

        <div wire:loading.delay class="h-0.5 w-full animate-pulse bg-(--brand-primary)"></div>

        @if ($leads->isEmpty())
            <div class="p-6">
                <x-ui.empty-state icon="inbox" title="No leads found"
                    :description="$search || $status ? 'Try adjusting your filters.' : 'Create the first lead to get started.'" />
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="bg-(--surface-muted) text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr>
                            <th class="px-4 py-3"><button wire:click="sortBy('name')" class="hover:text-(--content)">Name{{ $sort === 'name' ? ($direction === 'asc' ? ' ↑' : ' ↓') : '' }}</button></th>
                            <th class="px-4 py-3">Contact</th>
                            <th class="px-4 py-3">Source</th>
                            <th class="px-4 py-3">Assigned</th>
                            <th class="px-4 py-3"><button wire:click="sortBy('status')" class="hover:text-(--content)">Status{{ $sort === 'status' ? ($direction === 'asc' ? ' ↑' : ' ↓') : '' }}</button></th>
                            <th class="px-4 py-3"><button wire:click="sortBy('follow_up_at')" class="hover:text-(--content)">Follow-up{{ $sort === 'follow_up_at' ? ($direction === 'asc' ? ' ↑' : ' ↓') : '' }}</button></th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($leads as $lead)
                            <tr wire:key="lead-{{ $lead->id }}" class="hover:bg-(--surface-muted)/50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('leads.show', $lead) }}" wire:navigate class="font-medium text-(--content) hover:text-(--brand-primary)">{{ $lead->name }}</a>
                                </td>
                                <td class="px-4 py-3 text-(--content-muted)">
                                    <div>{{ $lead->phone }}</div>
                                    @if ($lead->email)<div class="text-xs">{{ $lead->email }}</div>@endif
                                </td>
                                <td class="px-4 py-3 text-(--content-muted)">{{ $lead->source?->name ?? '—' }}</td>
                                <td class="px-4 py-3 text-(--content-muted)">{{ $lead->assignedTo?->name ?? 'Unassigned' }}</td>
                                <td class="px-4 py-3"><x-ui.badge :variant="$lead->status->color()">{{ $lead->status->label() }}</x-ui.badge></td>
                                <td class="px-4 py-3 text-(--content-muted)">
                                    @if ($lead->follow_up_at)
                                        <span @class(['text-red-600 font-medium' => $lead->follow_up_at->isPast()])>
                                            {{ $lead->follow_up_at->format('d M, H:i') }}
                                        </span>
                                    @else — @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <x-ui.button variant="ghost" size="sm" :href="route('leads.show', $lead)" wire:navigate>View</x-ui.button>
                                        @can('convert', $lead)
                                            @if ($lead->status === LeadStatus::Qualified)
                                                <x-ui.button variant="ghost" size="sm" :href="route('leads.convert', $lead)" wire:navigate>Convert</x-ui.button>
                                            @endif
                                        @endcan
                                        @can('delete', $lead)
                                            <x-ui.button variant="ghost" size="sm" class="text-red-600"
                                                wire:click="delete({{ $lead->id }})" wire:confirm="Delete this lead?">Delete</x-ui.button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $leads->onEachSide(1)->links() }}</div>
        @endif
    </x-ui.card>
</div>
