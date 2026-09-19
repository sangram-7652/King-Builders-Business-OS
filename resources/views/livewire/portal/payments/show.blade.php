<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Payments', 'url' => route('portal.payments.index')],
        ['label' => $payment->payment_number],
    ]" />

    <div class="flex flex-wrap items-start justify-between gap-3">
        <h1 class="text-xl font-semibold">{{ $payment->payment_number }}</h1>
        <x-ui.badge :variant="$payment->status->color()">{{ $payment->status->label() }}</x-ui.badge>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Payment" class="lg:col-span-2">
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="text-(--content-muted)">Booking</dt>
                    <dd><a href="{{ route('portal.bookings.show', $payment->booking_id) }}" wire:navigate class="text-(--brand-primary) hover:underline">{{ $payment->booking?->booking_number }}</a></dd></div>
                <div><dt class="text-(--content-muted)">Date</dt><dd>{{ $payment->payment_date?->format('d M Y') }}</dd></div>
                <div><dt class="text-(--content-muted)">Amount</dt><dd class="tabular-nums font-semibold">₹{{ number_format((float) $payment->amount, 2) }}</dd></div>
                <div><dt class="text-(--content-muted)">Mode</dt><dd>{{ $payment->paymentMode?->name }}</dd></div>
                @if ($payment->reference_number)
                    <div><dt class="text-(--content-muted)">Reference</dt><dd>{{ $payment->reference_number }}</dd></div>
                @endif
            </dl>
        </x-ui.card>

        <x-ui.card title="Receipt">
            @if ($receipt)
                <p class="text-sm">{{ $receipt->receipt_number }}</p>
                <a href="{{ route('portal.receipts.pdf', $receipt->id) }}" target="_blank"
                   class="mt-3 inline-flex items-center gap-2 rounded-lg border border-(--border) px-3 py-1.5 text-sm font-medium hover:bg-(--surface-muted)">
                    Download receipt (PDF)
                </a>
            @else
                <p class="text-sm text-(--content-muted)">No receipt is available for this payment yet.</p>
            @endif
        </x-ui.card>
    </div>
</div>
