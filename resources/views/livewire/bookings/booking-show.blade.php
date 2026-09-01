@php use App\Enums\BookingStatus; @endphp

<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Bookings', 'url' => route('bookings.index')],
        ['label' => $booking->booking_number],
    ]" />

    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold tracking-tight text-(--content)">{{ $booking->booking_number }}</h1>
                <x-ui.badge :variant="$booking->status->color()">{{ $booking->status->label() }}</x-ui.badge>
                @if ($booking->price_overridden)
                    <x-ui.badge variant="warning">Price overridden</x-ui.badge>
                @endif
            </div>
            <p class="mt-1 text-sm text-(--content-muted)">
                {{ $booking->project?->name }} · {{ $booking->block?->name }} · Plot {{ $booking->plot?->plot_number }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($booking->isEditable())
                @can('update', $booking)
                    <x-ui.button variant="secondary" size="sm" :href="route('bookings.edit', $booking)" wire:navigate>Edit</x-ui.button>
                @endcan
                @can('overridePricing', $booking)
                    <x-ui.button variant="secondary" size="sm" wire:click="openOverride">Override price</x-ui.button>
                @endcan
            @endif

            @if ($booking->isDraft())
                @can('update', $booking)
                    <x-ui.button size="sm" wire:click="submit"
                        wire:confirm="Submit this booking for confirmation? The plot will be claimed.">Submit</x-ui.button>
                @endcan
            @endif

            @can('confirm', $booking)
                <x-ui.button size="sm" wire:click="confirm"
                    wire:confirm="Confirm this booking? Pricing will be frozen and the plot marked BOOKED.">Confirm</x-ui.button>
            @endcan

            @can('cancel', $booking)
                <x-ui.button variant="danger" size="sm" wire:click="openCancel">Cancel booking</x-ui.button>
            @endcan

            @can('delete', $booking)
                <x-ui.button variant="ghost" size="sm" class="text-red-600" wire:click="delete"
                    wire:confirm="Delete this booking? This cannot be undone.">Delete</x-ui.button>
            @endcan
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Details --}}
        <x-ui.card title="Details" class="lg:col-span-2">
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="text-(--content-muted)">Project</dt><dd class="mt-0.5">{{ $booking->project?->name ?? '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Block</dt><dd class="mt-0.5">{{ $booking->block?->name ?? '—' }}</dd></div>
                <div>
                    <dt class="text-(--content-muted)">Plot</dt>
                    <dd class="mt-0.5">
                        @if ($booking->plot)
                            <a class="text-(--brand-primary) hover:underline" wire:navigate
                               href="{{ route('plots.show', ['project' => $booking->project_id, 'block' => $booking->block_id, 'plot' => $booking->plot_id]) }}">
                                {{ $booking->plot->plot_number }}
                            </a>
                            ({{ $booking->plot->status->label() }})
                        @else — @endif
                    </dd>
                </div>
                <div><dt class="text-(--content-muted)">Booking date</dt><dd class="mt-0.5">{{ $booking->booking_date?->format('d M Y') ?? '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Created by</dt><dd class="mt-0.5">{{ $booking->createdBy?->name ?? '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Confirmed</dt>
                    <dd class="mt-0.5">
                        {{ $booking->confirmed_at?->format('d M Y H:i') ?? '—' }}
                        @if ($booking->confirmedBy) <span class="text-(--content-muted)">by {{ $booking->confirmedBy->name }}</span> @endif
                    </dd>
                </div>
                @if ($booking->notes)
                    <div class="sm:col-span-2"><dt class="text-(--content-muted)">Notes</dt><dd class="mt-0.5 whitespace-pre-line">{{ $booking->notes }}</dd></div>
                @endif
            </dl>
        </x-ui.card>

        {{-- Buyers --}}
        <x-ui.card title="Buyers">
            <ul class="space-y-3 text-sm">
                @foreach ($booking->bookingBuyers as $bb)
                    <li wire:key="bb-{{ $bb->id }}" class="flex items-start justify-between gap-2">
                        <div>
                            <div class="font-medium text-(--content)">
                                {{ $bb->buyer?->fullName() ?? '—' }}
                                @if ($bb->is_primary)<x-ui.badge size="sm" variant="brand" class="ml-1">Primary</x-ui.badge>@endif
                            </div>
                            <div class="text-xs text-(--content-muted)">{{ $bb->buyer?->customer_code }}</div>
                        </div>
                        <div class="tabular-nums text-(--content-muted)">{{ rtrim(rtrim(number_format((float) $bb->ownership_percentage, 2), '0'), '.') }}%</div>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    </div>

    {{-- Pricing breakdown --}}
    @can('viewPricing', $booking)
        <x-ui.card title="Pricing breakdown"
            subtitle="{{ $booking->isConfirmed() ? 'Frozen snapshot — future master changes do not affect this booking.' : 'Preview — recalculated on the server before every save and on confirmation.' }}">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr>
                            <th class="py-2 pr-4">Component</th>
                            <th class="py-2 pr-4">Type</th>
                            <th class="py-2 pr-4">Basis</th>
                            <th class="py-2 pr-4 text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($booking->priceLines as $line)
                            <tr>
                                <td class="py-2 pr-4">
                                    {{ $line->name }}
                                    @if (data_get($line->metadata, 'override'))<x-ui.badge size="sm" variant="warning" class="ml-1">override</x-ui.badge>@endif
                                </td>
                                <td class="py-2 pr-4 text-(--content-muted)">{{ $line->type->label() }}</td>
                                <td class="py-2 pr-4 text-(--content-muted)">
                                    @if ($line->calculation_type->value === 'percentage')
                                        {{ rtrim(rtrim(number_format((float) $line->rate, 4), '0'), '.') }}%
                                    @elseif ($line->calculation_type->value === 'per_sqft')
                                        ₹{{ number_format((float) $line->rate, 2) }} × {{ rtrim(rtrim(number_format((float) $line->quantity, 4), '0'), '.') }}
                                    @else
                                        Fixed
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">
                                    {{ $line->type->isDeduction() ? '−' : '' }}₹{{ number_format((float) $line->amount, 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="text-sm">
                        <tr><td colspan="3" class="py-1 pr-4 text-right text-(--content-muted)">Base</td><td class="py-1 pr-4 text-right tabular-nums">₹{{ number_format((float) $booking->base_amount, 2) }}</td></tr>
                        <tr><td colspan="3" class="py-1 pr-4 text-right text-(--content-muted)">PLC</td><td class="py-1 pr-4 text-right tabular-nums">₹{{ number_format((float) $booking->plc_amount, 2) }}</td></tr>
                        <tr><td colspan="3" class="py-1 pr-4 text-right text-(--content-muted)">Other charges</td><td class="py-1 pr-4 text-right tabular-nums">₹{{ number_format((float) $booking->charge_amount, 2) }}</td></tr>
                        <tr><td colspan="3" class="py-1 pr-4 text-right font-medium">Subtotal</td><td class="py-1 pr-4 text-right font-medium tabular-nums">₹{{ number_format((float) $booking->subtotal, 2) }}</td></tr>
                        <tr><td colspan="3" class="py-1 pr-4 text-right text-(--content-muted)">Discount</td><td class="py-1 pr-4 text-right tabular-nums">−₹{{ number_format((float) $booking->discount_amount, 2) }}</td></tr>
                        <tr><td colspan="3" class="py-1 pr-4 text-right text-(--content-muted)">Tax</td><td class="py-1 pr-4 text-right tabular-nums">₹{{ number_format((float) $booking->tax_amount, 2) }}</td></tr>
                        <tr class="border-t border-(--border)"><td colspan="3" class="py-2 pr-4 text-right text-base font-semibold">Final amount</td><td class="py-2 pr-4 text-right text-base font-semibold tabular-nums">₹{{ number_format((float) $booking->final_amount, 2) }}</td></tr>
                    </tfoot>
                </table>
            </div>

            @if ($booking->price_overridden)
                <div class="mt-4 rounded-lg border border-(--border) bg-(--surface-muted) p-3 text-xs text-(--content-muted)">
                    Price overridden by {{ $booking->priceOverrideBy?->name ?? 'a user' }}
                    on {{ $booking->price_override_at?->format('d M Y H:i') }} —
                    “{{ $booking->price_override_reason }}”
                </div>
            @endif

            @if ($booking->isConfirmed() && $booking->pricing_snapshot)
                <p class="mt-3 text-xs text-(--content-muted)">
                    Snapshot engine version {{ data_get($booking->pricing_snapshot, 'engine_version') }},
                    frozen {{ \Illuminate\Support\Carbon::parse(data_get($booking->pricing_snapshot, 'calculated_at'))->format('d M Y H:i') }}.
                </p>
            @endif
        </x-ui.card>
    @endcan

    @if ($booking->isCancelled())
        <x-ui.card title="Cancellation">
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="text-(--content-muted)">Cancelled</dt><dd class="mt-0.5">{{ $booking->cancelled_at?->format('d M Y H:i') ?? '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">By</dt><dd class="mt-0.5">{{ $booking->cancelledBy?->name ?? '—' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-(--content-muted)">Reason</dt><dd class="mt-0.5">{{ $booking->cancellation_reason ?: '—' }}</dd></div>
            </dl>
            <p class="mt-3 text-xs text-(--content-muted)">Cancellation releases the plot only. No refund, penalty or payment reversal is handled here.</p>
        </x-ui.card>
    @endif

    {{-- Cancel dialog --}}
    @if ($showCancel)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" wire:key="cancel-dialog">
            <div class="absolute inset-0 bg-slate-900/50" wire:click="closeCancel"></div>
            <div class="relative w-full max-w-md rounded-xl border border-(--border) bg-(--surface) shadow-xl">
                <div class="border-b border-(--border) px-5 py-4"><h3 class="text-sm font-semibold">Cancel {{ $booking->booking_number }}</h3></div>
                <form wire:submit="cancel" class="space-y-4 px-5 py-4">
                    <x-ui.textarea label="Reason" wire:model="cancelReason" rows="2" :error="$errors->first('cancelReason')"
                        hint="Recorded on the booking. No refund or penalty is processed." />
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="secondary" wire:click="closeCancel">Keep booking</x-ui.button>
                        <x-ui.button type="submit" variant="danger">Cancel booking</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Override dialog --}}
    @if ($showOverride)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" wire:key="override-dialog">
            <div class="absolute inset-0 bg-slate-900/50" wire:click="closeOverride"></div>
            <div class="relative w-full max-w-md rounded-xl border border-(--border) bg-(--surface) shadow-xl">
                <div class="border-b border-(--border) px-5 py-4"><h3 class="text-sm font-semibold">Override final price</h3></div>
                <form wire:submit="applyOverride" class="space-y-4 px-5 py-4">
                    <x-ui.input type="number" step="0.01" label="Target final amount (₹)" wire:model="overrideAmount"
                        :error="$errors->first('overrideAmount')" />
                    <x-ui.textarea label="Reason (required)" wire:model="overrideReason" rows="2" :error="$errors->first('overrideReason')" />
                    <p class="text-xs text-(--content-muted)">
                        The difference is added as a labelled adjustment line — the calculated components are not changed.
                    </p>
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="secondary" wire:click="closeOverride">Cancel</x-ui.button>
                        <x-ui.button type="submit">Apply override</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
