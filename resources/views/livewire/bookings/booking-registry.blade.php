@php use App\Enums\RegistryStatus; @endphp
<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Bookings', 'url' => route('bookings.index')],
        ['label' => $booking->booking_number, 'url' => route('bookings.show', $booking)],
        ['label' => 'Registry'],
    ]" />

    <x-ui.page-header :title="'Registry — '.$booking->booking_number"
        description="{{ $booking->project?->name }} · Plot {{ $booking->plot?->plot_number }}.">
        <x-slot:actions>
            <x-ui.button variant="secondary" size="sm" :href="route('documents.booking', $booking)" wire:navigate>Documents</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card title="Registry">
        <div class="flex items-center gap-4">
            <span class="text-sm text-(--content-muted)">Status:</span>
            <x-ui.badge :variant="$booking->registry_status->color()">{{ $booking->registry_status->label() }}</x-ui.badge>

            @if ($booking->registry_status === RegistryStatus::Pending)
                @can('registry.complete')
                    <x-ui.button size="sm" wire:click="markDone" wire:confirm="Mark Registry as Done? The plot will become Sold.">
                        Mark Done
                    </x-ui.button>
                @endcan
            @endif
        </div>

        @if ($booking->registry_status === RegistryStatus::Done)
            <p class="mt-3 text-sm text-(--content-muted)">Registry is Done. Plot {{ $booking->plot?->plot_number }} is Sold.</p>
        @else
            <p class="mt-3 text-sm text-(--content-muted)">Registry is Pending. The plot stays Booked until Registry is marked Done.</p>
        @endif
    </x-ui.card>

    @if ($history->isNotEmpty())
        <x-ui.card title="History">
            <ul class="space-y-2 text-sm">
                @foreach ($history as $event)
                    <li class="flex items-start gap-2">
                        <span class="text-(--content-muted)">{{ $event->created_at->format('d M Y H:i') }}</span>
                        <span>{{ $event->description }}</span>
                        <span class="text-(--content-muted)">— {{ $event->causer?->name ?? 'system' }}</span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif
</div>
