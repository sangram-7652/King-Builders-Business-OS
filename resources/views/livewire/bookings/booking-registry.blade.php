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

            @can('registry.complete')
                @if ($booking->registry_status->nextAction() === RegistryStatus::Done)
                    <x-ui.button size="sm" wire:click="markDone" wire:confirm="Mark Registry as Done? Plot {{ $booking->plot?->plot_number }} will become Sold.">
                        Mark Done
                    </x-ui.button>
                @else
                    <x-ui.button size="sm" variant="danger" wire:click="markUndone" wire:confirm="Mark Registry as Undone? Plot {{ $booking->plot?->plot_number }} will go back to Booked.">
                        Mark Undone
                    </x-ui.button>
                @endif
            @endcan
        </div>

        <p class="mt-3 text-sm text-(--content-muted)">
            @switch($booking->registry_status)
                @case(RegistryStatus::Done)
                    Registry is Done. Plot {{ $booking->plot?->plot_number }} is Sold.
                    @break
                @case(RegistryStatus::Undone)
                    Registry was reversed. Plot {{ $booking->plot?->plot_number }} is Booked until Registry is marked Done again.
                    @break
                @default
                    Registry is Pending. Plot {{ $booking->plot?->plot_number }} stays Booked until Registry is marked Done.
            @endswitch
        </p>
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
