@php use App\Enums\PossessionStatus; @endphp
<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Bookings', 'url' => route('bookings.index')],
        ['label' => $booking->booking_number, 'url' => route('bookings.show', $booking)],
        ['label' => 'Possession'],
    ]" />

    <x-ui.page-header :title="'Possession — '.$booking->booking_number"
        description="{{ $booking->project?->name }} · Plot {{ $booking->plot?->plot_number }}.">
        <x-slot:actions>
            <x-ui.button variant="secondary" size="sm" :href="route('documents.booking', $booking)" wire:navigate>Documents</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card title="Possession">
        <div class="flex items-center gap-4">
            <span class="text-sm text-(--content-muted)">Status:</span>
            <x-ui.badge :variant="$booking->possession_status->color()">{{ $booking->possession_status->label() }}</x-ui.badge>

            @can('possession.complete')
                @if ($booking->possession_status->nextAction() === PossessionStatus::Done)
                    <x-ui.button size="sm" wire:click="markDone" wire:confirm="Mark Possession as Done?">
                        Mark Done
                    </x-ui.button>
                @else
                    <x-ui.button size="sm" variant="danger" wire:click="markUndone" wire:confirm="Mark Possession as Undone?">
                        Mark Undone
                    </x-ui.button>
                @endif
            @endcan
        </div>

        <p class="mt-3 text-sm text-(--content-muted)">
            @switch($booking->possession_status)
                @case(PossessionStatus::Done)
                    Possession is Done — handover completed.
                    @break
                @case(PossessionStatus::Undone)
                    Possession was reversed — handover is no longer marked complete.
                    @break
                @default
                    Possession is Pending.
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
