<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Bookings', 'url' => route('bookings.index')],
        ['label' => $booking->booking_number, 'url' => route('bookings.show', $booking)],
        ['label' => 'Transfers'],
    ]" />

    <x-ui.page-header :title="'Transfers — '.$booking->booking_number" description="Plot Transfer only — the buyer and booking never change." />

    <x-ui.card title="Current Plot">
        <div class="flex items-center justify-between">
            <dl class="text-sm">
                <div><dt class="text-(--content-muted)">Plot</dt><dd class="mt-0.5 font-semibold">Plot {{ $booking->plot?->plot_number }}</dd></div>
                <div class="mt-2"><dt class="text-(--content-muted)">Block</dt><dd class="mt-0.5">{{ $booking->block?->name }}</dd></div>
            </dl>
            @can('transfer.complete')
                <x-ui.button size="sm" wire:click="openTransfer">Transfer Plot</x-ui.button>
            @endcan
        </div>

        @if ($showTransfer)
            <form wire:submit="transferPlot" class="mt-4 space-y-3 rounded-lg border border-(--border) p-4">
                <p class="text-xs font-semibold uppercase tracking-wider text-(--content-muted)">Transfer Plot</p>

                <div>
                    <p class="text-xs text-(--content-muted)">Current Plot</p>
                    <p class="text-sm font-semibold">Plot {{ $booking->plot?->plot_number }}</p>
                </div>

                <x-ui.select label="New Plot" wire:model="newPlotId" placeholder="Select available plot" :options="$availablePlots->toArray()" :error="$errors->first('newPlotId')" />
                <x-ui.input label="Reason" wire:model="reason" :error="$errors->first('reason')" hint="Optional" />

                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showTransfer', false)">Cancel</x-ui.button>
                    <x-ui.button type="submit">Transfer Plot</x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.card>

    <x-ui.card title="Transfer History">
        @if ($history->isEmpty())
            <x-ui.empty-state icon="inbox" title="No transfers yet" description="Completed plot transfers for this booking will appear here." />
        @else
            <ul class="space-y-3 text-sm">
                @foreach ($history as $transfer)
                    <li class="rounded-lg border border-(--border) p-3">
                        <div class="flex items-center justify-between">
                            <span class="font-semibold">Plot {{ $transfer->plot?->plot_number }} → Plot {{ $transfer->newPlot?->plot_number }}</span>
                            <x-ui.badge variant="success">Completed</x-ui.badge>
                        </div>
                        <dl class="mt-2 grid gap-1 text-(--content-muted) sm:grid-cols-2">
                            <div>{{ $transfer->completed_at?->format('d M Y H:i') }}</div>
                            <div>Transferred by {{ $transfer->completedBy?->name ?? '—' }}</div>
                            @if ($transfer->reason)
                                <div class="sm:col-span-2">Reason: {{ $transfer->reason }}</div>
                            @endif
                        </dl>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
