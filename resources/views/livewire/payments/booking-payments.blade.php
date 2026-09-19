<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Bookings', 'url' => route('bookings.index')],
        ['label' => $booking->booking_number, 'url' => route('bookings.show', $booking)],
        ['label' => 'Payments'],
    ]" />

    <x-ui.page-header title="Payments — {{ $booking->booking_number }}"
        description="{{ $booking->project?->name }} · Plot {{ $booking->plot?->plot_number }}. Financial truth is derived from the payment ledger — never a stored balance." />

    {{-- Summary --}}
    <div class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card label="Total" :value="'₹'.number_format((float) $summary->total->store(), 2)" />
        <x-ui.stat-card label="Paid" :value="'₹'.number_format((float) $summary->paid->store(), 2)" />
        <x-ui.stat-card label="Outstanding" :value="'₹'.number_format((float) $summary->outstanding->store(), 2)" />
    </div>

    {{-- Payments --}}
    <x-ui.card title="Payments">
        <x-slot:actions>
            @can('create', App\Models\Payment::class)
                <x-ui.button size="sm" wire:click="$toggle('showRecord')">Record payment</x-ui.button>
            @endcan
        </x-slot:actions>

        @if ($showRecord)
            <form wire:submit="recordPayment" class="mb-6 space-y-4 rounded-lg border border-(--border) p-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.select label="Mode" wire:model.live="payMode" placeholder="Select…"
                        :options="$paymentModes->pluck('name', 'id')->toArray()" :error="$errors->first('payMode')" />
                    <x-ui.input label="Amount (₹)" type="number" step="0.01" wire:model="payAmount" :error="$errors->first('payAmount')" />
                    <x-ui.input label="Date" type="date" wire:model="payDate" :error="$errors->first('payDate')" />
                </div>
                <x-ui.input label="Reference number" wire:model="payReference" :error="$errors->first('payReference')" />
                @php $selectedMode = $paymentModes->firstWhere('id', (int) $payMode); @endphp
                @if ($selectedMode?->is_cheque)
                    <div class="grid gap-4 sm:grid-cols-3">
                        <x-ui.input label="Cheque number" wire:model="chequeNumber" :error="$errors->first('chequeNumber')" />
                        <x-ui.input label="Cheque bank" wire:model="chequeBank" />
                        <x-ui.input label="Cheque date" type="date" wire:model="chequeDate" />
                    </div>
                @endif
                <x-ui.textarea label="Notes" wire:model="payNotes" rows="2" />
                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showRecord', false)">Cancel</x-ui.button>
                    <x-ui.button type="submit">Record payment</x-ui.button>
                </div>
            </form>
        @endif

        @if ($payments->isEmpty())
            <x-ui.empty-state icon="inbox" title="No payments yet" description="Recorded payments start as pending until verified." />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr><th class="py-2 pr-4">Payment</th><th class="py-2 pr-4">Date</th><th class="py-2 pr-4">Mode</th>
                            <th class="py-2 pr-4">Reference</th><th class="py-2 pr-4 text-right">Amount</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2 pr-4 text-right">Actions</th></tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($payments as $p)
                            <tr wire:key="pay-{{ $p->id }}">
                                <td class="py-2 pr-4">
                                    <a href="{{ route('payments.show', $p) }}" wire:navigate class="font-medium text-(--brand-primary) hover:underline">{{ $p->payment_number }}</a>
                                    @if ($p->cheque_status)<div class="text-xs text-(--content-muted)">Cheque: {{ $p->cheque_status->label() }}</div>@endif
                                </td>
                                <td class="py-2 pr-4">{{ $p->payment_date->format('d M Y') }}</td>
                                <td class="py-2 pr-4">{{ $p->paymentMode?->name }}</td>
                                <td class="py-2 pr-4 text-(--content-muted)">{{ $p->reference_number ?: '—' }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">₹{{ number_format((float) $p->amount, 2) }}</td>
                                <td class="py-2 pr-4"><x-ui.badge :variant="$p->status->color()">{{ $p->status->label() }}</x-ui.badge></td>
                                <td class="py-2 pr-4">
                                    <div class="flex items-center justify-end gap-1">
                                        @can('verify', $p)
                                            <x-ui.button size="sm" variant="ghost" wire:click="verify({{ $p->id }}, 'success')" wire:confirm="Verify this payment as successful?">✓ Verify</x-ui.button>
                                            <x-ui.button size="sm" variant="ghost" class="text-red-600" wire:click="verify({{ $p->id }}, 'failed')" wire:confirm="Mark this payment failed?">✕ Fail</x-ui.button>
                                        @endcan
                                        @can('reverse', $p)
                                            <x-ui.button size="sm" variant="ghost" class="text-red-600" wire:click="openReverse({{ $p->id }})">Reverse</x-ui.button>
                                        @endcan
                                        @if ($p->receipt)
                                            <x-ui.button size="sm" variant="ghost" :href="route('receipts.show', $p->receipt)" wire:navigate>Receipt</x-ui.button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    {{-- Reverse dialog --}}
    @if ($reversingPaymentId)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" wire:key="reverse-dialog">
            <div class="absolute inset-0 bg-slate-900/50" wire:click="$set('reversingPaymentId', null)"></div>
            <div class="relative w-full max-w-md rounded-xl border border-(--border) bg-(--surface) shadow-xl">
                <div class="border-b border-(--border) px-5 py-4"><h3 class="text-sm font-semibold">Reverse payment</h3></div>
                <form wire:submit="reverse" class="space-y-4 px-5 py-4">
                    <x-ui.textarea label="Reason (required)" wire:model="reverseReason" rows="2" :error="$errors->first('reverseReason')" />
                    <p class="text-xs text-(--content-muted)">The payment stays in history as REVERSED, stops counting toward Paid, and its receipt is voided. No money is deleted.</p>
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="secondary" wire:click="$set('reversingPaymentId', null)">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="danger">Reverse payment</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
