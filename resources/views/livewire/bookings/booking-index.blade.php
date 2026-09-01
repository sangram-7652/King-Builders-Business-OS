<div class="space-y-6">
    <x-ui.page-header title="Bookings" description="Plot bookings — the financial and inventory boundary. Confirmed bookings hold a frozen price snapshot.">
        <x-slot:actions>
            @can('create', App\Models\Booking::class)
                <x-ui.button :href="route('bookings.create')" wire:navigate>
                    <x-app.icon name="plus" class="size-4" /> New booking
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :padding="false">
        <div class="flex flex-col gap-3 border-b border-(--border) p-4 sm:flex-row sm:flex-wrap sm:items-end">
            <div class="min-w-44 flex-1">
                <x-ui.input label="Search" wire:model.live.debounce.300ms="search" placeholder="Booking number…" />
            </div>
            <div class="w-full sm:w-40"><x-ui.select label="Status" wire:model.live="status" placeholder="All" :options="$statuses" /></div>
            <div class="w-full sm:w-48"><x-ui.select label="Project" wire:model.live="project" placeholder="All" :options="$projects->toArray()" /></div>
            @if ($search || $status || $project)
                <x-ui.button variant="ghost" wire:click="clearFilters">Clear</x-ui.button>
            @endif
        </div>

        <div wire:loading.delay class="h-0.5 w-full animate-pulse bg-(--brand-primary)"></div>

        @if ($bookings->isEmpty())
            <div class="p-6">
                <x-ui.empty-state icon="inbox" title="No bookings found"
                    :description="$search || $status ? 'Try adjusting your filters.' : 'Create the first booking to get started.'" />
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="bg-(--surface-muted) text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr>
                            <th class="px-4 py-3"><button wire:click="sortBy('booking_number')" class="hover:text-(--content)">Booking{{ $sort === 'booking_number' ? ($direction === 'asc' ? ' ↑' : ' ↓') : '' }}</button></th>
                            <th class="px-4 py-3">Project / Plot</th>
                            <th class="px-4 py-3">Primary buyer</th>
                            <th class="px-4 py-3"><button wire:click="sortBy('booking_date')" class="hover:text-(--content)">Date{{ $sort === 'booking_date' ? ($direction === 'asc' ? ' ↑' : ' ↓') : '' }}</button></th>
                            <th class="px-4 py-3 text-right"><button wire:click="sortBy('final_amount')" class="hover:text-(--content)">Final amount{{ $sort === 'final_amount' ? ($direction === 'asc' ? ' ↑' : ' ↓') : '' }}</button></th>
                            <th class="px-4 py-3"><button wire:click="sortBy('status')" class="hover:text-(--content)">Status{{ $sort === 'status' ? ($direction === 'asc' ? ' ↑' : ' ↓') : '' }}</button></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($bookings as $booking)
                            <tr wire:key="booking-{{ $booking->id }}" class="hover:bg-(--surface-muted)/50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('bookings.show', $booking) }}" wire:navigate class="font-medium text-(--content) hover:text-(--brand-primary)">{{ $booking->booking_number }}</a>
                                </td>
                                <td class="px-4 py-3 text-(--content-muted)">
                                    <div>{{ $booking->project?->name ?? '—' }}</div>
                                    <div class="text-xs">Plot {{ $booking->plot?->plot_number ?? '—' }}</div>
                                </td>
                                <td class="px-4 py-3 text-(--content-muted)">
                                    {{ $booking->primaryBookingBuyer?->buyer?->fullName() ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-(--content-muted)">{{ $booking->booking_date?->format('d M Y') ?? '—' }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">₹{{ number_format((float) $booking->final_amount, 2) }}</td>
                                <td class="px-4 py-3"><x-ui.badge :variant="$booking->status->color()">{{ $booking->status->label() }}</x-ui.badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $bookings->onEachSide(1)->links() }}</div>
        @endif
    </x-ui.card>
</div>
