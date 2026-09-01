@php use App\Enums\AgingBucket; @endphp
<div class="space-y-6">
    <x-ui.page-header title="Collections dashboard"
        description="Every figure is derived from the M7 payment ledger — collection is a layer over payments, not a second balance engine." />

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat-card label="Total receivable" :value="'₹'.number_format((float) $data->totalReceivable->store(), 2)" />
        <x-ui.stat-card label="Total collected" :value="'₹'.number_format((float) $data->totalCollected->store(), 2)" />
        <x-ui.stat-card label="Total outstanding" :value="'₹'.number_format((float) $data->totalOutstanding->store(), 2)" />
        <x-ui.stat-card label="Total overdue" :value="'₹'.number_format((float) $data->totalOverdue->store(), 2)" trend="down" />
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat-card label="Due today" :value="'₹'.number_format((float) $data->dueToday->store(), 2)" />
        <x-ui.stat-card label="Due this week" :value="'₹'.number_format((float) $data->dueThisWeek->store(), 2)" />
        <x-ui.stat-card label="Collected today" :value="'₹'.number_format((float) $data->collectedToday->store(), 2)" />
        <x-ui.stat-card label="Expected today" :value="'₹'.number_format((float) $data->expectedToday->store(), 2)" />
    </div>

    <x-ui.card title="Overdue aging">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-(--border) text-sm">
                <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                    <tr><th class="py-2 pr-4">Bucket</th><th class="py-2 pr-4 text-right">Amount</th><th class="py-2 pr-4 text-right">Bookings</th><th class="py-2 pr-4 text-right">Customers</th></tr>
                </thead>
                <tbody class="divide-y divide-(--border)">
                    @foreach (AgingBucket::cases() as $bucket)
                        @php $row = $data->aging[$bucket->value]; @endphp
                        <tr>
                            <td class="py-2 pr-4"><x-ui.badge :variant="$bucket->color()">{{ $bucket->label() }}</x-ui.badge></td>
                            <td class="py-2 pr-4 text-right tabular-nums">₹{{ number_format((float) $row['amount']->store(), 2) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['bookings'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $row['customers'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
        <x-ui.stat-card label="Open follow-ups" :value="$data->openFollowUps" />
        <x-ui.stat-card label="Promises due" :value="$data->promisesDue" />
        <x-ui.stat-card label="Broken promises" :value="$data->brokenPromises" />
        <x-ui.stat-card label="Pending cheques" :value="$data->pendingCheques" />
        <x-ui.stat-card label="Bounced cheques" :value="$data->bouncedCheques" />
        <x-ui.stat-card label="Open cases" :value="$data->openCases" />
    </div>

    <div class="flex gap-2">
        <x-ui.button :href="route('collections.queue')" wire:navigate>Collection queue</x-ui.button>
        @can('viewReports', App\Models\CollectionCase::class)
            <x-ui.button variant="secondary" :href="route('collections.reports')" wire:navigate>Reports</x-ui.button>
        @endcan
    </div>
</div>
