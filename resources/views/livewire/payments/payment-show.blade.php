<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Payments', 'url' => route('payments.index')],
        ['label' => $payment->payment_number],
    ]" />

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold tracking-tight text-(--content)">{{ $payment->payment_number }}</h1>
                <x-ui.badge :variant="$payment->status->color()">{{ $payment->status->label() }}</x-ui.badge>
                @if ($payment->cheque_status)<x-ui.badge :variant="$payment->cheque_status->color()">Cheque: {{ $payment->cheque_status->label() }}</x-ui.badge>@endif
            </div>
            <p class="mt-1 text-sm text-(--content-muted)">
                Booking <a href="{{ route('bookings.show', $payment->booking) }}" wire:navigate class="text-(--brand-primary) hover:underline">{{ $payment->booking->booking_number }}</a>
                · {{ $payment->booking->project?->name }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @can('verify', $payment)
                <x-ui.button size="sm" wire:click="verify('success')" wire:confirm="Verify this payment as successful? A receipt will be issued.">Verify success</x-ui.button>
                <x-ui.button size="sm" variant="ghost" class="text-red-600" wire:click="verify('failed')" wire:confirm="Mark this payment failed?">Mark failed</x-ui.button>
            @endcan
            @can('reverse', $payment)
                <x-ui.button size="sm" variant="danger" wire:click="$toggle('showReverse')">Reverse</x-ui.button>
            @endcan
            @if ($payment->receipt)
                <x-ui.button size="sm" variant="secondary" :href="route('receipts.show', $payment->receipt)" wire:navigate>Receipt</x-ui.button>
            @endif
        </div>
    </div>

    <x-ui.card title="Payment">
        <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div><dt class="text-(--content-muted)">Amount</dt><dd class="mt-0.5 text-lg font-semibold tabular-nums">₹{{ number_format((float) $payment->amount, 2) }}</dd></div>
            <div><dt class="text-(--content-muted)">Date</dt><dd class="mt-0.5">{{ $payment->payment_date->format('d M Y') }}</dd></div>
            <div><dt class="text-(--content-muted)">Mode</dt><dd class="mt-0.5">{{ $payment->paymentMode?->name }}</dd></div>
            <div><dt class="text-(--content-muted)">Reference</dt><dd class="mt-0.5">{{ $payment->reference_number ?: '—' }}</dd></div>
            <div><dt class="text-(--content-muted)">Received by</dt><dd class="mt-0.5">{{ $payment->receivedBy?->name ?? '—' }}</dd></div>
            <div><dt class="text-(--content-muted)">Verified</dt><dd class="mt-0.5">{{ $payment->verified_at?->format('d M Y H:i') ?? '—' }} {{ $payment->verifiedBy ? '· '.$payment->verifiedBy->name : '' }}</dd></div>
            @if ($payment->cheque_number)
                <div><dt class="text-(--content-muted)">Cheque number</dt><dd class="mt-0.5">{{ $payment->cheque_number }}</dd></div>
                <div><dt class="text-(--content-muted)">Cheque date</dt><dd class="mt-0.5">{{ $payment->cheque_date?->format('d M Y') }}</dd></div>
            @endif
            @if ($payment->isReversed())
                <div class="sm:col-span-2"><dt class="text-(--content-muted)">Reversed</dt>
                    <dd class="mt-0.5">{{ $payment->reversed_at?->format('d M Y H:i') }} by {{ $payment->reversedBy?->name }} — “{{ $payment->reversal_reason }}”</dd></div>
            @endif
            @if ($payment->notes)
                <div class="sm:col-span-2"><dt class="text-(--content-muted)">Notes</dt><dd class="mt-0.5 whitespace-pre-line">{{ $payment->notes }}</dd></div>
            @endif
        </dl>
    </x-ui.card>

    @if ($showReverse)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-slate-900/50" wire:click="$set('showReverse', false)"></div>
            <div class="relative w-full max-w-md rounded-xl border border-(--border) bg-(--surface) shadow-xl">
                <div class="border-b border-(--border) px-5 py-4"><h3 class="text-sm font-semibold">Reverse {{ $payment->payment_number }}</h3></div>
                <form wire:submit="reverse" class="space-y-4 px-5 py-4">
                    <x-ui.textarea label="Reason (required)" wire:model="reverseReason" rows="2" :error="$errors->first('reverseReason')" />
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="secondary" wire:click="$set('showReverse', false)">Cancel</x-ui.button>
                        <x-ui.button type="submit" variant="danger">Reverse</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
