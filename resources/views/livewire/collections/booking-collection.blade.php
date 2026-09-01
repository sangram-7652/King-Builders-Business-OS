<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Bookings', 'url' => route('bookings.index')],
        ['label' => $booking->booking_number, 'url' => route('bookings.show', $booking)],
        ['label' => 'Collection'],
    ]" />

    <x-ui.page-header :title="'Collection — '.$booking->booking_number"
        description="{{ $booking->project?->name }} · Plot {{ $booking->plot?->plot_number }}. Balances come from the M7 payment ledger." >
        <x-slot:actions>
            @if ($case)
                <x-ui.button size="sm" :href="route('collections.show', $case)" wire:navigate>Open case</x-ui.button>
            @else
                @can('create', App\Models\CollectionCase::class)
                    <x-ui.button size="sm" wire:click="openCase">Open collection case</x-ui.button>
                @endcan
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-4 sm:grid-cols-4">
        <x-ui.stat-card label="Total amount" :value="'₹'.number_format((float) $summary->total->store(), 2)" />
        <x-ui.stat-card label="Paid" :value="'₹'.number_format((float) $summary->paid->store(), 2)" />
        <x-ui.stat-card label="Outstanding" :value="'₹'.number_format((float) $summary->outstanding->store(), 2)" />
        <x-ui.stat-card label="Overdue" :value="'₹'.number_format((float) $summary->overdue->store(), 2)" />
    </div>

    <x-ui.card title="Installments">
        @if ($installments->isEmpty())
            <p class="text-sm text-(--content-muted)">No active payment plan.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr><th class="py-2 pr-4">#</th><th class="py-2 pr-4">Due date</th><th class="py-2 pr-4 text-right">Amount</th>
                            <th class="py-2 pr-4 text-right">Paid</th><th class="py-2 pr-4 text-right">Outstanding</th>
                            <th class="py-2 pr-4">Status</th><th class="py-2 pr-4 text-right">Days overdue</th></tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($installments as $row)
                            <tr wire:key="i-{{ $row['model']->id }}">
                                <td class="py-2 pr-4">{{ $row['model']->installment_number }}</td>
                                <td class="py-2 pr-4">{{ $row['model']->due_date->format('d M Y') }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">₹{{ number_format((float) $row['model']->amount, 2) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">₹{{ number_format((float) $row['paid']->store(), 2) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">₹{{ number_format((float) $row['outstanding']->store(), 2) }}</td>
                                <td class="py-2 pr-4"><x-ui.badge :variant="$row['model']->status->color()">{{ $row['model']->status->label() }}</x-ui.badge></td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $row['days_overdue'] > 0 ? $row['days_overdue'] : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    @if ($case)
        <div class="grid gap-6 lg:grid-cols-2">
            <x-ui.card title="Collection">
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-(--content-muted)">Owner</dt><dd class="mt-0.5">{{ $case->assignedTo?->name ?? 'Unassigned' }}</dd></div>
                    <div><dt class="text-(--content-muted)">Status</dt><dd class="mt-0.5"><x-ui.badge :variant="$case->status->color()">{{ $case->status->label() }}</x-ui.badge></dd></div>
                    <div><dt class="text-(--content-muted)">Priority</dt><dd class="mt-0.5"><x-ui.badge :variant="$case->priority->color()">{{ $case->priority->label() }}</x-ui.badge></dd></div>
                    <div><dt class="text-(--content-muted)">Next follow-up</dt><dd class="mt-0.5">{{ $case->next_follow_up_at?->format('d M Y H:i') ?? '—' }}</dd></div>
                    <div><dt class="text-(--content-muted)">Follow-ups</dt><dd class="mt-0.5">{{ $case->followUps->count() }}</dd></div>
                    <div><dt class="text-(--content-muted)">Promises</dt><dd class="mt-0.5">{{ $case->promises->count() }}</dd></div>
                </dl>
            </x-ui.card>

            <x-ui.card title="Recent timeline">
                <ol class="space-y-2 text-sm">
                    @foreach ($case->activities->take(8) as $activity)
                        <li class="border-l-2 border-(--border) pl-3">
                            <span class="font-medium">{{ $activity->type->label() }}</span>
                            <span class="text-(--content-muted)"> — {{ $activity->description }}</span>
                            <span class="block text-xs text-(--content-muted)">{{ $activity->created_at?->format('d M Y H:i') }}</span>
                        </li>
                    @endforeach
                </ol>
            </x-ui.card>
        </div>
    @endif
</div>
