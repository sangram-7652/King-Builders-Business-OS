<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Bookings', 'url' => route('bookings.index')],
        ['label' => $booking->booking_number, 'url' => route('bookings.show', $booking)],
        ['label' => 'Commission'],
    ]" />

    <x-ui.page-header :title="'Commission — '.$booking->booking_number"
        description="Channel-partner commission for this booking. Each figure is a frozen snapshot of the scheme, the M6/M7 basis and the co-broker share at the time it was calculated.">
        <x-slot:actions>
            @can('generate', App\Models\CommissionCase::class)
                @if ($booking->isConfirmed() && $booking->partnerAttributions->isNotEmpty())
                    <x-ui.button size="sm" wire:click="generate" wire:confirm="Generate / refresh commission cases for this booking?">Generate</x-ui.button>
                @endif
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    @unless ($booking->isConfirmed())
        <x-ui.alert variant="warning" title="Not confirmed">Commission is only generated for a confirmed booking.</x-ui.alert>
    @endif

    @if ($booking->partnerAttributions->isEmpty())
        <x-ui.alert variant="info" title="Direct sale">No channel partner is attributed to this booking, so there is no commission.</x-ui.alert>
    @endif

    @if ($cases->isEmpty())
        <x-ui.card><x-ui.empty-state icon="layers" title="No commission cases yet"
            description="Attribute a partner, confirm the booking, then generate." /></x-ui.card>
    @else
        <x-ui.card :padding="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="bg-(--surface-muted) text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr>
                            <th class="px-4 py-3">Case</th><th class="px-4 py-3">Partner</th>
                            <th class="px-4 py-3">Scheme</th><th class="px-4 py-3">Share</th>
                            <th class="px-4 py-3 text-right">Commission</th><th class="px-4 py-3">Status</th>
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
                                <td class="px-4 py-3 text-(--content-muted)">
                                    @if ($case->scheme){{ $case->scheme->code }} v{{ $case->scheme->version }}@else — @endif
                                </td>
                                <td class="px-4 py-3 tabular-nums">
                                    {{ $case->currentCalculation ? rtrim(rtrim(number_format((float) $case->currentCalculation->share_percentage, 2), '0'), '.').'%' : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums font-medium">
                                    @if ($case->is_eligible)
                                        ₹{{ number_format((float) $case->commission_amount, 2) }}
                                    @else
                                        <span class="text-xs text-(--content-muted)" title="{{ $case->eligibility_reason }}">Not eligible</span>
                                    @endif
                                </td>
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
