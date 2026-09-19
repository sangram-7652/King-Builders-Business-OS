<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Bookings', 'url' => route('bookings.index')],
        ['label' => $booking->booking_number, 'url' => route('bookings.show', $booking)],
        ['label' => 'Commission'],
    ]" />

    <x-ui.page-header :title="'Commission — '.$booking->booking_number"
        description="Promoter commission for this booking — Booking final amount × Commission %. Each figure is a frozen snapshot at the time it was calculated.">
        <x-slot:actions>
            @can('generate', App\Models\CommissionCase::class)
                @if ($booking->isConfirmed() && $booking->partnerAttributions->isNotEmpty())
                    <x-ui.button size="sm" wire:click="generate" wire:confirm="Generate / refresh the commission for this booking?">Generate</x-ui.button>
                @endif
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @unless ($booking->isConfirmed())
        <x-ui.alert variant="warning" title="Not confirmed">Commission is only generated for a confirmed booking.</x-ui.alert>
    @endif

    @if ($booking->partnerAttributions->isEmpty())
        <x-ui.alert variant="info" title="Direct sale">No promoter is attributed to this booking, so there is no commission.</x-ui.alert>
    @endif

    @if ($cases->isEmpty())
        <x-ui.card><x-ui.empty-state icon="layers" title="No commission case yet"
            description="Attribute a promoter, confirm the booking, then generate." /></x-ui.card>
    @else
        <x-ui.card :padding="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="bg-(--surface-muted) text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr>
                            <th class="px-4 py-3">Case</th><th class="px-4 py-3">Promoter</th>
                            <th class="px-4 py-3">Commission %</th>
                            <th class="px-4 py-3 text-right">Gross</th>
                            <th class="px-4 py-3 text-right">Advance adjusted</th>
                            <th class="px-4 py-3 text-right">Payable</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($cases as $case)
                            <tr wire:key="cc-{{ $case->id }}">
                                <td class="px-4 py-3">
                                    <a href="{{ route('commission-cases.show', $case) }}" wire:navigate class="font-medium text-(--brand-primary) hover:underline">{{ $case->case_number }}</a>
                                </td>
                                <td class="px-4 py-3">{{ $case->partner?->displayName() }}</td>
                                <td class="px-4 py-3 text-(--content-muted)">{{ $case->partner?->commissionRateLabel() ?? '—' }}</td>
                                <td class="px-4 py-3 text-right tabular-nums font-medium">
                                    @if ($case->is_eligible)
                                        ₹{{ number_format((float) $case->commission_amount, 2) }}
                                    @else
                                        <span class="text-xs text-(--content-muted)" title="{{ $case->eligibility_reason }}">Not eligible</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">₹{{ number_format((float) $case->advance_adjusted_amount, 2) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums font-medium">₹{{ number_format((float) $case->payable_amount, 2) }}</td>
                                <td class="px-4 py-3"><x-ui.badge :variant="$case->status->color()">{{ $case->status->label() }}</x-ui.badge></td>
                                <td class="px-4 py-3 text-right">
                                    @can('recalculate', $case)
                                        <x-ui.button variant="ghost" size="sm" wire:click="recalculate({{ $case->id }})">Recalculate</x-ui.button>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif
</div>
