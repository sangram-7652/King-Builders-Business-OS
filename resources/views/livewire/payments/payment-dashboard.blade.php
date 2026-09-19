<div class="space-y-6">
    <x-ui.page-header title="Finance" description="Collections position across all confirmed bookings. Every figure is derived from the payment ledger." />

    <div class="grid gap-4 sm:grid-cols-2">
        <x-ui.stat-card label="Confirmed bookings" :value="$confirmedBookings" />
        <x-ui.stat-card label="Total outstanding" :value="'₹'.number_format((float) $totalOutstanding->store(), 2)" />
    </div>

    <x-ui.card title="Pending verification">
        @if ($pendingVerification->isEmpty())
            <p class="text-sm text-(--content-muted)">Nothing awaiting verification.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr><th class="py-2 pr-4">Payment</th><th class="py-2 pr-4">Booking</th><th class="py-2 pr-4">Date</th><th class="py-2 pr-4">Mode</th><th class="py-2 pr-4 text-right">Amount</th></tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($pendingVerification as $p)
                            <tr>
                                <td class="py-2 pr-4"><a href="{{ route('payments.show', $p) }}" wire:navigate class="text-(--brand-primary) hover:underline">{{ $p->payment_number }}</a></td>
                                <td class="py-2 pr-4 text-(--content-muted)">{{ $p->booking?->booking_number }}</td>
                                <td class="py-2 pr-4 text-(--content-muted)">{{ $p->payment_date->format('d M Y') }}</td>
                                <td class="py-2 pr-4 text-(--content-muted)">{{ $p->paymentMode?->name }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">₹{{ number_format((float) $p->amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    <x-ui.card title="Bookings with a balance">
        @if (empty($rows))
            <p class="text-sm text-(--content-muted)">Every confirmed booking is fully paid.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr><th class="py-2 pr-4">Booking</th><th class="py-2 pr-4">Buyer</th>
                            <th class="py-2 pr-4 text-right">Total</th><th class="py-2 pr-4 text-right">Paid</th>
                            <th class="py-2 pr-4 text-right">Outstanding</th><th></th></tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="py-2 pr-4">{{ $row['booking']->booking_number }}<div class="text-xs text-(--content-muted)">{{ $row['booking']->project?->name }}</div></td>
                                <td class="py-2 pr-4 text-(--content-muted)">{{ $row['booking']->primaryBookingBuyer?->buyer?->fullName() ?? '—' }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">₹{{ number_format((float) $row['summary']->total->store(), 2) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">₹{{ number_format((float) $row['summary']->paid->store(), 2) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums text-red-600">₹{{ number_format((float) $row['summary']->outstanding->store(), 2) }}</td>
                                <td class="py-2 pr-4 text-right"><a href="{{ route('payments.booking', $row['booking']) }}" wire:navigate class="text-(--brand-primary) hover:underline">Manage</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>
</div>
