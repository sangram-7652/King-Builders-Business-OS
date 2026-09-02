@php use App\Enums\TransferRequestStatus as S; @endphp
<div class="space-y-6">
    <x-ui.page-header title="Transfer dashboard" description="Ownership / nominee transfer requests by stage." />

    <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
        <x-ui.stat-card label="New" :value="($counts[S::Draft->value] ?? 0) + ($counts[S::Submitted->value] ?? 0)" />
        <x-ui.stat-card label="Under review" :value="$counts[S::UnderReview->value] ?? 0" />
        <x-ui.stat-card label="Documents pending" :value="$counts[S::DocumentsPending->value] ?? 0" />
        <x-ui.stat-card label="Approval pending" :value="$counts[S::Approved->value] ?? 0" />
        <x-ui.stat-card label="Completed" :value="$counts[S::Completed->value] ?? 0" />
        <x-ui.stat-card label="Rejected" :value="$counts[S::Rejected->value] ?? 0" trend="down" />
    </div>

    <x-ui.card :padding="false">
        <div class="flex flex-col gap-3 border-b border-(--border) p-4 sm:flex-row sm:items-end">
            <div class="min-w-44 flex-1"><x-ui.input label="Search" wire:model.live.debounce.300ms="search" placeholder="Request or booking number…" /></div>
            <div class="w-full sm:w-48"><x-ui.select label="Status" wire:model.live="status" placeholder="All" :options="$statuses" /></div>
        </div>

        @if ($transfers->isEmpty())
            <div class="p-6"><x-ui.empty-state icon="inbox" title="No transfer requests" /></div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="bg-(--surface-muted) text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr><th class="px-4 py-3">Request</th><th class="px-4 py-3">Booking / Plot</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">From → To</th><th class="px-4 py-3">Status</th></tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($transfers as $t)
                            <tr wire:key="tr-{{ $t->id }}" class="hover:bg-(--surface-muted)/50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('transfers.booking', $t->booking) }}" wire:navigate class="font-medium text-(--brand-primary) hover:underline">{{ $t->request_number }}</a>
                                </td>
                                <td class="px-4 py-3 text-(--content-muted)">
                                    {{ $t->booking?->booking_number }}<div class="text-xs">{{ $t->booking?->project?->name }} · Plot {{ $t->plot?->plot_number }}</div>
                                </td>
                                <td class="px-4 py-3 text-(--content-muted)">{{ $t->transfer_type->label() }}</td>
                                <td class="px-4 py-3 text-(--content-muted) text-xs">
                                    {{ $t->currentBuyer?->fullName() ?? '—' }} → {{ $t->newBuyer?->fullName() ?? '—' }}
                                </td>
                                <td class="px-4 py-3"><x-ui.badge :variant="$t->status->color()">{{ $t->status->label() }}</x-ui.badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $transfers->onEachSide(1)->links() }}</div>
        @endif
    </x-ui.card>
</div>
