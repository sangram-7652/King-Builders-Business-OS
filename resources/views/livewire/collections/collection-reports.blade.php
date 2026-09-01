<div class="space-y-6">
    <x-ui.page-header title="Collection reports" description="All figures derived from the M7 payment ledger." />

    <x-ui.card :padding="false">
        <div class="flex flex-col gap-3 border-b border-(--border) p-4 sm:flex-row sm:flex-wrap sm:items-end">
            <div class="w-full sm:w-56"><x-ui.select label="Report" wire:model.live="report" :options="$reports" /></div>
            @if ($report === 'performance')
                <div class="w-full sm:w-40"><x-ui.input type="date" label="From" wire:model.live="from" /></div>
                <div class="w-full sm:w-40"><x-ui.input type="date" label="To" wire:model.live="to" /></div>
            @endif
        </div>

        <div class="overflow-x-auto p-4">
            @if (empty($rows))
                <x-ui.empty-state icon="inbox" title="Nothing to report" />
            @else
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr>@foreach (array_keys($rows[0]) as $col)<th class="px-3 py-2">{{ \Illuminate\Support\Str::headline($col) }}</th>@endforeach</tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($rows as $row)
                            <tr wire:key="r-{{ $loop->index }}">
                                @foreach ($row as $col => $value)
                                    <td class="px-3 py-2 {{ str_contains($col, 'amount') || in_array($col, ['total','paid','outstanding'], true) ? 'text-right tabular-nums' : 'text-(--content-muted)' }}">
                                        {{ (str_contains($col, 'amount') || in_array($col, ['total','paid','outstanding'], true)) ? '₹'.number_format((float) $value, 2) : $value }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </x-ui.card>
</div>
