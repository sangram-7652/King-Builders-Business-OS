<div class="space-y-6">
    <x-ui.page-header title="Collection queue" description="Who owes money, how overdue, who owns the case." >
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('collections.dashboard')" wire:navigate>Dashboard</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :padding="false">
        <div class="flex flex-col gap-3 border-b border-(--border) p-4 sm:flex-row sm:flex-wrap sm:items-end">
            <div class="min-w-44 flex-1"><x-ui.input label="Search" wire:model.live.debounce.300ms="search" placeholder="Customer, booking, plot or phone…" /></div>
            <div class="w-full sm:w-36"><x-ui.select label="Priority" wire:model.live="priority" placeholder="Any" :options="$priorities" /></div>
            <div class="w-full sm:w-36"><x-ui.select label="Status" wire:model.live="status" placeholder="Open" :options="$statuses" /></div>
            <div class="w-full sm:w-40">
                <x-ui.select label="Owner" wire:model.live="owner" placeholder="Anyone">
                    <option value="me">Me</option>
                    <option value="unassigned">Unassigned</option>
                    @foreach ($owners as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </x-ui.select>
            </div>
            <div class="w-full sm:w-40"><x-ui.select label="Project" wire:model.live="project" placeholder="All" :options="$projects->toArray()" /></div>
            <div class="w-full sm:w-36"><x-ui.select label="Aging" wire:model.live="bucket" placeholder="Any" :options="$buckets" /></div>
            <div class="w-full sm:w-36"><x-ui.select label="Promise" wire:model.live="promise" placeholder="Any" :options="$promiseStatuses" /></div>
            @if ($search || $priority || $status || $owner || $project || $bucket || $promise)
                <x-ui.button variant="ghost" wire:click="clearFilters">Clear</x-ui.button>
            @endif
        </div>

        @if ($rows->isEmpty())
            <div class="p-6"><x-ui.empty-state icon="inbox" title="No collection cases" description="Cases open automatically when an installment goes overdue." /></div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="bg-(--surface-muted) text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr>
                            <th class="px-4 py-3">Customer</th><th class="px-4 py-3">Booking / Plot</th>
                            <th class="px-4 py-3 text-right">Outstanding</th><th class="px-4 py-3 text-right">Overdue</th>
                            <th class="px-4 py-3 text-right">Days</th><th class="px-4 py-3">Priority</th>
                            <th class="px-4 py-3">Owner</th><th class="px-4 py-3">Next follow-up</th><th class="px-4 py-3">Promise</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($rows as $row)
                            @php $case = $row['case']; $b = $case->booking; @endphp
                            <tr wire:key="case-{{ $case->id }}" class="hover:bg-(--surface-muted)/50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('collections.show', $case) }}" wire:navigate class="font-medium text-(--brand-primary) hover:underline">
                                        {{ $b->primaryBookingBuyer?->buyer?->fullName() ?? '—' }}
                                    </a>
                                    <div class="text-xs text-(--content-muted)">{{ $b->primaryBookingBuyer?->buyer?->phone }}</div>
                                </td>
                                <td class="px-4 py-3 text-(--content-muted)">
                                    {{ $b->booking_number }}<div class="text-xs">{{ $b->project?->name }} · Plot {{ $b->plot?->plot_number }}</div>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">₹{{ number_format((float) $row['outstanding']->store(), 2) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-red-600">₹{{ number_format((float) $row['overdue']->store(), 2) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ $row['days_overdue'] }}</td>
                                <td class="px-4 py-3"><x-ui.badge :variant="$case->priority->color()">{{ $case->priority->label() }}</x-ui.badge></td>
                                <td class="px-4 py-3 text-(--content-muted)">{{ $case->assignedTo?->name ?? 'Unassigned' }}</td>
                                <td class="px-4 py-3 text-(--content-muted)">{{ $case->next_follow_up_at?->format('d M, H:i') ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    @if ($row['promise'])<x-ui.badge :variant="$row['promise']->status->color()">{{ $row['promise']->status->label() }}</x-ui.badge>@else — @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $cases->onEachSide(1)->links() }}</div>
        @endif
    </x-ui.card>
</div>
