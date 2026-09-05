@php use App\Enums\InstallmentStatus; @endphp

<div class="space-y-6">
    <h1 class="text-xl font-semibold">Payments</h1>

    <div class="flex gap-1 border-b border-(--border)">
        <button wire:click="setTab('schedule')" @class(['px-3 py-2 text-sm font-medium', 'border-b-2 border-(--brand-primary) text-(--content)' => $tab === 'schedule', 'text-(--content-muted)' => $tab !== 'schedule'])>Schedule</button>
        <button wire:click="setTab('history')" @class(['px-3 py-2 text-sm font-medium', 'border-b-2 border-(--brand-primary) text-(--content)' => $tab === 'history', 'text-(--content-muted)' => $tab !== 'history'])>Payments made</button>
    </div>

    @if ($tab === 'schedule')
        @forelse ($bookingSchedules as $bs)
            <x-ui.card :title="$bs['booking']->booking_number.' — '.$bs['booking']->project?->name">
                <x-slot:actions>
                    <span class="text-sm text-(--content-muted)">
                        Outstanding <span class="font-medium text-(--content)">₹{{ number_format((float) $bs['summary']->outstanding->store(), 0) }}</span>
                    </span>
                </x-slot:actions>

                @if ($bs['schedule'])
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-(--border) text-sm">
                            <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                                <tr><th class="py-2 pr-4">#</th><th class="py-2 pr-4">Due</th><th class="py-2 pr-4 text-right">Amount</th>
                                    <th class="py-2 pr-4 text-right">Paid</th><th class="py-2 pr-4 text-right">Outstanding</th><th class="py-2 pr-4">Status</th></tr>
                            </thead>
                            <tbody class="divide-y divide-(--border)">
                                @foreach ($bs['schedule']['rows'] as $row)
                                    @php $st = InstallmentStatus::from($row['status']); @endphp
                                    <tr>
                                        <td class="py-2 pr-4 tabular-nums">{{ $row['number'] }}</td>
                                        <td class="py-2 pr-4">{{ \Illuminate\Support\Carbon::parse($row['due_date'])->format('d M Y') }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">₹{{ number_format((float) $row['amount'], 2) }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">₹{{ number_format((float) $row['paid'], 2) }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">₹{{ number_format((float) $row['outstanding'], 2) }}</td>
                                        <td class="py-2 pr-4"><x-ui.badge :variant="$st->color()" size="sm">{{ $st->label() }}</x-ui.badge></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-sm text-(--content-muted)">No payment plan has been set up for this booking yet.</p>
                @endif
            </x-ui.card>
        @empty
            <x-ui.card><x-ui.empty-state icon="inbox" title="No confirmed bookings" /></x-ui.card>
        @endforelse
    @else
        <x-ui.card :padding="false">
            @if ($payments->isEmpty())
                <div class="p-6"><x-ui.empty-state icon="inbox" title="No payments recorded yet" /></div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-(--border) text-sm">
                        <thead class="bg-(--surface-muted) text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                            <tr><th class="px-4 py-3">Payment</th><th class="px-4 py-3">Booking</th><th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">Mode</th><th class="px-4 py-3 text-right">Amount</th><th class="px-4 py-3">Receipt</th></tr>
                        </thead>
                        <tbody class="divide-y divide-(--border)">
                            @foreach ($payments as $payment)
                                <tr class="hover:bg-(--surface-muted)/50">
                                    <td class="px-4 py-3"><a href="{{ route('portal.payments.show', $payment->id) }}" wire:navigate class="font-medium text-(--brand-primary) hover:underline">{{ $payment->payment_number }}</a></td>
                                    <td class="px-4 py-3 text-(--content-muted)">{{ $payment->booking?->booking_number }}</td>
                                    <td class="px-4 py-3">{{ $payment->payment_date?->format('d M Y') }}</td>
                                    <td class="px-4 py-3 text-(--content-muted)">{{ $payment->paymentMode?->name }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums font-medium">₹{{ number_format((float) $payment->amount, 2) }}</td>
                                    <td class="px-4 py-3">
                                        @if ($payment->receipt)
                                            <a href="{{ route('portal.receipts.pdf', $payment->receipt->id) }}" target="_blank" class="text-(--brand-primary) hover:underline">{{ $payment->receipt->receipt_number }}</a>
                                        @else — @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="border-t border-(--border) p-4">{{ $payments->links() }}</div>
            @endif
        </x-ui.card>
    @endif
</div>
