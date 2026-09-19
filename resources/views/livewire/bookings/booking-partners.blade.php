<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Bookings', 'url' => route('bookings.index')],
        ['label' => $booking->booking_number, 'url' => route('bookings.show', $booking)],
        ['label' => 'Promoter'],
    ]" />

    <x-ui.page-header :title="'Promoter — '.$booking->booking_number"
        description="Attribute this booking to one promoter (optional). Booking value, receivable and commission are separate concepts — this only records who is credited." />

    <x-ui.card title="Promoter">
        <x-slot:actions>
            @if ($booking->partnerAttributions->isNotEmpty())
                <x-ui.button variant="ghost" size="sm" class="text-red-600" wire:click="makeDirect"
                    wire:confirm="Clear the promoter and mark this a direct sale?">Make direct</x-ui.button>
            @endif
        </x-slot:actions>

        <div class="max-w-md">
            <x-ui.select label="Promoter" wire:model="partnerId" placeholder="No promoter — direct sale">
                @foreach ($partners as $partner)
                    <option value="{{ $partner->id }}">{{ $partner->displayName() }} ({{ $partner->partner_code }}) — {{ $partner->commissionRateLabel() }}</option>
                @endforeach
            </x-ui.select>
        </div>

        <div class="mt-4 flex justify-end gap-2 border-t border-(--border) pt-4">
            <x-ui.button variant="secondary" :href="route('bookings.show', $booking)" wire:navigate>Back to booking</x-ui.button>
            <x-ui.button wire:click="save">Save</x-ui.button>
        </div>
    </x-ui.card>

    @if ($booking->partnerAttributionHistory->isNotEmpty())
        <x-ui.card title="Promoter history">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr>
                            <th class="py-2 pr-4">Rev</th><th class="py-2 pr-4">Promoter</th>
                            <th class="py-2 pr-4">Status</th><th class="py-2 pr-4">When</th><th class="py-2 pr-4">By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($booking->partnerAttributionHistory as $row)
                            <tr wire:key="hist-{{ $row->id }}" @class(['text-(--content-muted)' => ! $row->isActive()])>
                                <td class="py-2 pr-4 tabular-nums">{{ $row->revision }}</td>
                                <td class="py-2 pr-4">{{ $row->partner?->displayName() ?? '—' }}</td>
                                <td class="py-2 pr-4">
                                    <x-ui.badge :variant="$row->isActive() ? 'success' : 'muted'" size="sm">{{ ucfirst($row->status) }}</x-ui.badge>
                                </td>
                                <td class="py-2 pr-4">{{ $row->attributed_at->format('d M Y H:i') }}</td>
                                <td class="py-2 pr-4">{{ $row->attributedBy?->name ?? 'System' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif
</div>
