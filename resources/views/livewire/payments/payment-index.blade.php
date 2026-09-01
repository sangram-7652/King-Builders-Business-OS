<div class="space-y-6">
    <x-ui.page-header title="Payments" description="Every payment across all bookings. Only SUCCESS payments move balances." />

    <x-ui.card :padding="false">
        <div class="flex flex-col gap-3 border-b border-(--border) p-4 sm:flex-row sm:flex-wrap sm:items-end">
            <div class="min-w-44 flex-1">
                <x-ui.input label="Search" wire:model.live.debounce.300ms="search" placeholder="Payment number or reference…" />
            </div>
            <div class="w-full sm:w-40"><x-ui.select label="Status" wire:model.live="status" placeholder="All" :options="$statuses" /></div>
            @if ($search || $status)
                <x-ui.button variant="ghost" wire:click="clearFilters">Clear</x-ui.button>
            @endif
        </div>

        @if ($payments->isEmpty())
            <div class="p-6"><x-ui.empty-state icon="inbox" title="No payments found" /></div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="bg-(--surface-muted) text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr><th class="px-4 py-3">Payment</th><th class="px-4 py-3">Booking</th><th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Mode</th><th class="px-4 py-3 text-right">Amount</th>
                            <th class="px-4 py-3">Status</th><th class="px-4 py-3">Receipt</th></tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($payments as $p)
                            <tr wire:key="p-{{ $p->id }}" class="hover:bg-(--surface-muted)/50">
                                <td class="px-4 py-3"><a href="{{ route('payments.show', $p) }}" wire:navigate class="font-medium text-(--brand-primary) hover:underline">{{ $p->payment_number }}</a></td>
                                <td class="px-4 py-3 text-(--content-muted)">{{ $p->booking?->booking_number }}</td>
                                <td class="px-4 py-3 text-(--content-muted)">{{ $p->payment_date->format('d M Y') }}</td>
                                <td class="px-4 py-3 text-(--content-muted)">{{ $p->paymentMode?->name }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">₹{{ number_format((float) $p->amount, 2) }}</td>
                                <td class="px-4 py-3"><x-ui.badge :variant="$p->status->color()">{{ $p->status->label() }}</x-ui.badge></td>
                                <td class="px-4 py-3 text-(--content-muted)">
                                    @if ($p->receipt)
                                        <a href="{{ route('receipts.show', $p->receipt) }}" wire:navigate class="text-(--brand-primary) hover:underline">{{ $p->receipt->receipt_number }}</a>
                                    @else — @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $payments->onEachSide(1)->links() }}</div>
        @endif
    </x-ui.card>
</div>
