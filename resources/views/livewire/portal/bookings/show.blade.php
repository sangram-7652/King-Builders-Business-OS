@php use App\Enums\DocumentStatus; @endphp

<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'My bookings', 'url' => route('portal.bookings.index')],
        ['label' => $booking->booking_number],
    ]" />

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold">{{ $booking->booking_number }}</h1>
            <p class="text-sm text-(--content-muted)">
                {{ $booking->project?->name }}
                @if ($booking->block) · {{ $booking->block->name }} @endif
                @if ($booking->plot) · Plot {{ $booking->plot->plot_number }} @endif
            </p>
        </div>
        <x-ui.badge :variant="$booking->status->color()">{{ $booking->status->label() }}</x-ui.badge>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Booking" class="lg:col-span-2">
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="text-(--content-muted)">Booking date</dt><dd>{{ $booking->booking_date?->format('d M Y') }}</dd></div>
                <div><dt class="text-(--content-muted)">Status</dt><dd>{{ $booking->status->label() }}</dd></div>
                <div><dt class="text-(--content-muted)">Booking value</dt><dd class="tabular-nums font-semibold">₹{{ number_format((float) $booking->final_amount, 2) }}</dd></div>
                <div><dt class="text-(--content-muted)">Agreement</dt><dd>{{ $booking->agreement?->status?->label() ?? 'Not started' }}</dd></div>
                <div><dt class="text-(--content-muted)">Registry</dt><dd>{{ $booking->registryCase?->status?->label() ?? 'Not started' }}</dd></div>
                <div><dt class="text-(--content-muted)">Possession</dt><dd>{{ $booking->possessionCase?->status?->label() ?? 'Not started' }}</dd></div>
                @if ($transfer)
                    <div><dt class="text-(--content-muted)">Transfer</dt><dd>{{ $transfer->request_number }} — {{ $transfer->status->label() }}</dd></div>
                @endif
            </dl>

            @if ($booking->priceLines->isNotEmpty())
                <div class="mt-4 border-t border-(--border) pt-3">
                    <p class="text-xs font-semibold uppercase tracking-wider text-(--content-muted)">Price breakup</p>
                    <table class="mt-1 min-w-full text-sm">
                        <tbody>
                            <tr><td class="py-1 pr-4 text-(--content-muted)">Base</td><td class="py-1 text-right tabular-nums">₹{{ number_format((float) $booking->base_amount, 2) }}</td></tr>
                            <tr><td class="py-1 pr-4 text-(--content-muted)">PLC</td><td class="py-1 text-right tabular-nums">₹{{ number_format((float) $booking->plc_amount, 2) }}</td></tr>
                            <tr><td class="py-1 pr-4 text-(--content-muted)">Other charges</td><td class="py-1 text-right tabular-nums">₹{{ number_format((float) $booking->charge_amount, 2) }}</td></tr>
                            <tr><td class="py-1 pr-4 text-(--content-muted)">Discount</td><td class="py-1 text-right tabular-nums">−₹{{ number_format((float) $booking->discount_amount, 2) }}</td></tr>
                            <tr><td class="py-1 pr-4 text-(--content-muted)">Tax</td><td class="py-1 text-right tabular-nums">₹{{ number_format((float) $booking->tax_amount, 2) }}</td></tr>
                            <tr class="border-t border-(--border)"><td class="py-1 pr-4 font-semibold">Final</td><td class="py-1 text-right font-semibold tabular-nums">₹{{ number_format((float) $booking->final_amount, 2) }}</td></tr>
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

        <x-ui.card title="Payment summary">
            @if ($financials)
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-(--content-muted)">Total</dt><dd class="tabular-nums">₹{{ number_format((float) $financials->total->store(), 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-(--content-muted)">Paid</dt><dd class="tabular-nums">₹{{ number_format((float) $financials->paid->store(), 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-(--content-muted)">Outstanding</dt><dd class="tabular-nums font-medium">₹{{ number_format((float) $financials->outstanding->store(), 2) }}</dd></div>
                </dl>
                <x-ui.button variant="secondary" size="sm" class="mt-3 w-full" :href="route('portal.payments.index')" wire:navigate>All payments</x-ui.button>
            @else
                <p class="text-sm text-(--content-muted)">Payment details are available once your booking is confirmed.</p>
            @endif
        </x-ui.card>
    </div>

    @if (! empty($schedule['rows']))
        <x-ui.card title="Payment history">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr><th class="py-2 pr-4">Payment</th><th class="py-2 pr-4">Date</th><th class="py-2 pr-4">Mode</th>
                            <th class="py-2 pr-4 text-right">Amount</th><th class="py-2 pr-4">Receipt</th></tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($schedule['rows'] as $row)
                            <tr>
                                <td class="py-2 pr-4">{{ $row['payment_number'] }}</td>
                                <td class="py-2 pr-4">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d M Y') }}</td>
                                <td class="py-2 pr-4">{{ $row['mode'] }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">₹{{ number_format((float) $row['amount'], 2) }}</td>
                                <td class="py-2 pr-4">{{ $row['receipt_number'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif
</div>
