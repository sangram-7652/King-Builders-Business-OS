@php use App\Enums\BookingStatus; @endphp

<div class="space-y-6">
    <h1 class="text-xl font-semibold">My bookings</h1>

    @if ($bookings->isEmpty())
        <x-ui.card><x-ui.empty-state icon="inbox" title="No bookings yet" /></x-ui.card>
    @else
        <div class="space-y-4">
            @foreach ($bookings as $booking)
                @php $f = $financials[$booking->id] ?? null; @endphp
                <x-ui.card>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <a href="{{ route('portal.bookings.show', $booking->id) }}" wire:navigate
                               class="font-semibold text-(--brand-primary) hover:underline">{{ $booking->booking_number }}</a>
                            <p class="text-sm text-(--content-muted)">
                                {{ $booking->project?->name }}
                                @if ($booking->block) · {{ $booking->block->name }} @endif
                                @if ($booking->plot) · Plot {{ $booking->plot->plot_number }} @endif
                            </p>
                            <p class="text-xs text-(--content-muted)">Booked {{ $booking->booking_date?->format('d M Y') }}</p>
                        </div>
                        <x-ui.badge :variant="$booking->status->color()">{{ $booking->status->label() }}</x-ui.badge>
                    </div>

                    <dl class="mt-4 grid gap-3 border-t border-(--border) pt-3 text-sm sm:grid-cols-4">
                        <div><dt class="text-(--content-muted)">Booking value</dt><dd class="tabular-nums font-medium">₹{{ number_format((float) $booking->final_amount, 0) }}</dd></div>
                        <div><dt class="text-(--content-muted)">Paid</dt><dd class="tabular-nums font-medium">₹{{ $f ? number_format((float) $f->paid->store(), 0) : '—' }}</dd></div>
                        <div><dt class="text-(--content-muted)">Outstanding</dt><dd class="tabular-nums font-medium">₹{{ $f ? number_format((float) $f->outstanding->store(), 0) : '—' }}</dd></div>
                        <div><dt class="text-(--content-muted)">Overdue</dt><dd @class(['tabular-nums font-medium', 'text-red-600' => $f && $f->overdue->isPositive()])>₹{{ $f ? number_format((float) $f->overdue->store(), 0) : '—' }}</dd></div>
                    </dl>
                </x-ui.card>
            @endforeach
        </div>

        {{ $bookings->links() }}
    @endif
</div>
