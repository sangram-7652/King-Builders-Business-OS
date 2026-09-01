<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Payments', 'url' => route('payments.index')],
        ['label' => $receipt->payment->payment_number, 'url' => route('payments.show', $receipt->payment)],
        ['label' => $receipt->receipt_number],
    ]" />

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-wrap items-center gap-2">
            <h1 class="text-xl font-semibold tracking-tight text-(--content)">{{ $receipt->receipt_number }}</h1>
            @if ($receipt->isVoided())<x-ui.badge variant="danger">Void</x-ui.badge>@endif
        </div>
        @can('generate', $receipt)
            <x-ui.button size="sm" href="{{ route('receipts.pdf', $receipt) }}" target="_blank">Download PDF</x-ui.button>
        @endcan
    </div>

    @if ($receipt->isVoided())
        <x-ui.alert variant="danger" title="This receipt has been voided">{{ $receipt->void_reason }}</x-ui.alert>
    @endif

    <x-ui.card title="Receipt">
        <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div><dt class="text-(--content-muted)">Receipt number</dt><dd class="mt-0.5">{{ $receipt->receipt_number }}</dd></div>
            <div><dt class="text-(--content-muted)">Payment</dt><dd class="mt-0.5">
                <a href="{{ route('payments.show', $receipt->payment) }}" wire:navigate class="text-(--brand-primary) hover:underline">{{ $receipt->payment->payment_number }}</a>
            </dd></div>
            <div><dt class="text-(--content-muted)">Project</dt><dd class="mt-0.5">{{ $receipt->booking->project?->name ?? '—' }}</dd></div>
            <div><dt class="text-(--content-muted)">Booking</dt><dd class="mt-0.5">{{ $receipt->booking->booking_number }}</dd></div>
            <div><dt class="text-(--content-muted)">Received from</dt><dd class="mt-0.5">{{ $receipt->buyer_name_snapshot }}</dd></div>
            <div><dt class="text-(--content-muted)">Payment mode</dt><dd class="mt-0.5">{{ $receipt->payment_mode_label }}</dd></div>
            <div><dt class="text-(--content-muted)">Reference</dt><dd class="mt-0.5">{{ $receipt->reference_number ?: '—' }}</dd></div>
            <div><dt class="text-(--content-muted)">Payment date</dt><dd class="mt-0.5">{{ $receipt->payment_date->format('d M Y') }}</dd></div>
            <div><dt class="text-(--content-muted)">Amount</dt><dd class="mt-0.5 text-lg font-semibold tabular-nums">₹{{ number_format((float) $receipt->amount, 2) }}</dd></div>
            <div><dt class="text-(--content-muted)">Issued</dt><dd class="mt-0.5">{{ $receipt->issued_at?->format('d M Y H:i') }} {{ $receipt->issuedBy ? '· '.$receipt->issuedBy->name : '' }}</dd></div>
        </dl>
    </x-ui.card>
</div>
