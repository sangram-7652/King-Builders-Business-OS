<div class="space-y-6">
    <div>
        <h1 class="text-xl font-semibold">Welcome, {{ $customer->first_name }}</h1>
        <p class="text-sm text-(--content-muted)">Here is a summary of your bookings and payments.</p>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.card>
            <p class="text-xs text-(--content-muted)">Bookings</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">{{ $summary['bookings'] }}</p>
            <p class="text-xs text-(--content-muted)">{{ $summary['plots'] }} plot(s)</p>
        </x-ui.card>
        <x-ui.card>
            <p class="text-xs text-(--content-muted)">Paid</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">₹{{ number_format((float) $summary['paid'], 0) }}</p>
            <p class="text-xs text-(--content-muted)">of ₹{{ number_format((float) $summary['total_value'], 0) }}</p>
        </x-ui.card>
        <x-ui.card>
            <p class="text-xs text-(--content-muted)">Outstanding</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums">₹{{ number_format((float) $summary['outstanding'], 0) }}</p>
        </x-ui.card>
        <x-ui.card>
            <p class="text-xs text-(--content-muted)">Overdue</p>
            <p @class(['mt-1 text-2xl font-semibold tabular-nums', 'text-red-600' => (float) $summary['overdue'] > 0])>
                ₹{{ number_format((float) $summary['overdue'], 0) }}
            </p>
        </x-ui.card>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Next payment due" class="lg:col-span-2">
            @if ($summary['next_due'])
                <div class="flex items-baseline justify-between">
                    <p class="text-2xl font-semibold tabular-nums">₹{{ number_format((float) $summary['next_due']['amount'], 2) }}</p>
                    <p class="text-sm text-(--content-muted)">due {{ \Illuminate\Support\Carbon::parse($summary['next_due']['due_date'])->format('d M Y') }}</p>
                </div>
                @if (\Illuminate\Support\Facades\Route::has('portal.payments.index'))
                    <x-ui.button variant="secondary" size="sm" class="mt-3" :href="route('portal.payments.index')" wire:navigate>View payment schedule</x-ui.button>
                @endif
            @else
                <p class="text-sm text-(--content-muted)">No upcoming payments — you're all caught up.</p>
            @endif

            @if ($summary['last_payment_date'])
                <p class="mt-4 border-t border-(--border) pt-3 text-sm text-(--content-muted)">
                    Last payment: ₹{{ number_format((float) $summary['last_payment_amount'], 2) }} on
                    {{ \Illuminate\Support\Carbon::parse($summary['last_payment_date'])->format('d M Y') }}
                </p>
            @endif
        </x-ui.card>

        <x-ui.card title="Documents">
            <p class="text-2xl font-semibold tabular-nums">{{ $summary['documents_pending'] }}</p>
            <p class="text-xs text-(--content-muted)">pending / not yet verified</p>
            @if (\Illuminate\Support\Facades\Route::has('portal.documents.index'))
                <x-ui.button variant="ghost" size="sm" class="mt-3 w-full" :href="route('portal.documents.index')" wire:navigate>View documents</x-ui.button>
            @endif
        </x-ui.card>
    </div>

    <x-ui.card title="Recent activity">
        @if ($recentActivity->isEmpty())
            <x-ui.empty-state icon="clock" title="No recent activity" />
        @else
            <ol class="space-y-2 text-sm">
                @foreach ($recentActivity as $act)
                    <li wire:key="ca-{{ $act->id }}" class="flex items-baseline justify-between gap-3">
                        <span>{{ $act->description }}</span>
                        <span class="shrink-0 text-xs text-(--content-muted)">{{ $act->created_at?->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-ui.card>
</div>
