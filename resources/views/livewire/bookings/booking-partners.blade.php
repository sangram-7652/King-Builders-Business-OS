@php use App\Enums\BookingAttributionRole; @endphp

<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Bookings', 'url' => route('bookings.index')],
        ['label' => $booking->booking_number, 'url' => route('bookings.show', $booking)],
        ['label' => 'Partners'],
    ]" />

    <x-ui.page-header :title="'Channel partners — '.$booking->booking_number"
        description="Attribute this booking to one or more channel partners. Co-broker shares must total exactly 100%. Booking value, receivable and commission are separate concepts — this only records who is credited." />

    <x-ui.card title="Attribution split">
        <x-slot:actions>
            @if ($booking->partnerAttributions->isNotEmpty())
                <x-ui.button variant="ghost" size="sm" class="text-red-600" wire:click="makeDirect"
                    wire:confirm="Clear all partner attribution and mark this a direct sale?">Make direct</x-ui.button>
            @endif
        </x-slot:actions>

        @if ($suggestedPartnerLabel && $rows === [])
            <x-ui.alert variant="info" title="Suggested from the originating lead">
                <p class="text-sm">{{ $suggestedPartnerLabel }} sourced a lead for a buyer on this booking.</p>
                <x-ui.button size="sm" class="mt-2" wire:click="applySuggestion">Use as primary (100%)</x-ui.button>
            </x-ui.alert>
        @endif

        @if ($rows === [])
            <p class="text-sm text-(--content-muted)">No partners attributed — this is a direct sale.</p>
        @else
            <div class="space-y-3">
                @foreach ($rows as $i => $row)
                    <div wire:key="row-{{ $i }}" class="flex flex-wrap items-end gap-3 rounded-lg border border-(--border) p-3">
                        <div class="min-w-52 flex-1">
                            <x-ui.select label="Partner" wire:model="rows.{{ $i }}.partner_id" placeholder="Select a partner…"
                                :error="$errors->first('rows.'.$i.'.partner_id')">
                                @foreach ($partners as $partner)
                                    <option value="{{ $partner->id }}">{{ $partner->displayName() }} ({{ $partner->partner_code }})</option>
                                @endforeach
                            </x-ui.select>
                        </div>
                        <div class="w-28">
                            <x-ui.input label="Share %" type="number" step="0.01" min="0" max="100"
                                wire:model.live="rows.{{ $i }}.share_percentage" />
                        </div>
                        <div class="w-32">
                            <x-ui.select label="Role" wire:model="rows.{{ $i }}.role" :options="$roles" :placeholder="null" />
                        </div>
                        <div class="flex items-center gap-1 pb-1">
                            @if (($row['role'] ?? '') !== BookingAttributionRole::Primary->value)
                                <x-ui.button type="button" variant="ghost" size="sm" wire:click="setPrimary({{ $i }})">Make primary</x-ui.button>
                            @endif
                            <x-ui.button type="button" variant="ghost" size="sm" class="text-red-600" wire:click="removeRow({{ $i }})">✕</x-ui.button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <x-ui.button type="button" variant="secondary" size="sm" wire:click="addRow">
                <x-app.icon name="plus" class="size-4" /> Add partner
            </x-ui.button>
            <div class="text-sm">
                Total:
                <span @class(['font-semibold tabular-nums', 'text-green-600' => $this->total === '100.00', 'text-red-600' => $this->total !== '100.00' && $rows !== []])>
                    {{ $this->total }}%
                </span>
            </div>
        </div>

        <div class="mt-4 flex justify-end gap-2 border-t border-(--border) pt-4">
            <x-ui.button variant="secondary" :href="route('bookings.show', $booking)" wire:navigate>Back to booking</x-ui.button>
            <x-ui.button wire:click="save" :disabled="$rows !== [] && $this->total !== '100.00'">Save attribution</x-ui.button>
        </div>
    </x-ui.card>

    @if ($booking->partnerAttributionHistory->isNotEmpty())
        <x-ui.card title="Attribution history">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr>
                            <th class="py-2 pr-4">Rev</th><th class="py-2 pr-4">Partner</th>
                            <th class="py-2 pr-4">Share</th><th class="py-2 pr-4">Role</th>
                            <th class="py-2 pr-4">Status</th><th class="py-2 pr-4">When</th><th class="py-2 pr-4">By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($booking->partnerAttributionHistory as $row)
                            <tr wire:key="hist-{{ $row->id }}" @class(['text-(--content-muted)' => ! $row->isActive()])>
                                <td class="py-2 pr-4 tabular-nums">{{ $row->revision }}</td>
                                <td class="py-2 pr-4">{{ $row->partner?->displayName() ?? '—' }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ rtrim(rtrim(number_format((float) $row->share_percentage, 2), '0'), '.') }}%</td>
                                <td class="py-2 pr-4">{{ $row->role->label() }}</td>
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
