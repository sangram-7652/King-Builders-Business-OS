@php use App\Enums\CommissionCaseStatus; @endphp

<div class="space-y-6">
    <x-ui.page-header title="Commissions"
        description="Every promoter commission case. Review, approve, record payouts and reverse from here." />

    <div class="grid gap-3 sm:grid-cols-4">
        @foreach (['pending_review' => 'Pending review', 'approved' => 'Approved', 'partially_paid' => 'Partially paid', 'paid' => 'Paid'] as $key => $label)
            @php $row = $summary->get($key); @endphp
            <x-ui.card>
                <p class="text-xs text-(--content-muted)">{{ $label }}</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">{{ $row->n ?? 0 }}</p>
                <p class="text-xs text-(--content-muted) tabular-nums">₹{{ number_format((float) ($row->payable ?? 0), 0) }} payable</p>
            </x-ui.card>
        @endforeach
    </div>

    <x-ui.card :padding="false">
        <div class="flex flex-col gap-3 border-b border-(--border) p-4 sm:flex-row sm:items-end">
            <div class="w-full sm:w-48"><x-ui.select label="Status" wire:model.live="status" placeholder="All" :options="$statuses" /></div>
            <div class="w-full sm:w-56"><x-ui.select label="Partner" wire:model.live="partner" placeholder="All" :options="$partners->toArray()" /></div>
        </div>

        @if ($cases->isEmpty())
            <div class="p-6"><x-ui.empty-state icon="layers" title="No commission cases" /></div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="bg-(--surface-muted) text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr>
                            <th class="px-4 py-3">Case</th><th class="px-4 py-3">Promoter</th>
                            <th class="px-4 py-3">Booking</th>
                            <th class="px-4 py-3 text-right">Gross</th>
                            <th class="px-4 py-3 text-right">Payable</th><th class="px-4 py-3 text-right">Paid</th>
                            <th class="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($cases as $case)
                            <tr wire:key="ci-{{ $case->id }}" class="hover:bg-(--surface-muted)/50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('commission-cases.show', $case) }}" wire:navigate class="font-medium text-(--brand-primary) hover:underline">{{ $case->case_number }}</a>
                                </td>
                                <td class="px-4 py-3">{{ $case->partner?->displayName() }}</td>
                                <td class="px-4 py-3 text-(--content-muted)">{{ $case->booking?->booking_number }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">₹{{ number_format((float) $case->commission_amount, 2) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">₹{{ number_format((float) $case->payable_amount, 2) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">₹{{ number_format((float) $case->paid_amount, 2) }}</td>
                                <td class="px-4 py-3"><x-ui.badge :variant="$case->status->color()">{{ $case->status->label() }}</x-ui.badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="border-t border-(--border) p-4">{{ $cases->links() }}</div>
        @endif
    </x-ui.card>
</div>
