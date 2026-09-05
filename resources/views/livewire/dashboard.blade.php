@php
    use App\Enums\LeadStatus;
    use App\Enums\PlotStatus;
    use App\Enums\BookingStatus;
    use App\Support\Reports\Kpi;
    use App\Support\Reports\ReportFormat;

    /** @var \App\Support\Dashboard\DashboardViewData $data */
    /** @var \App\Support\Reports\ReportFilterData $filters */
    /** @var string $metric */

    $user = auth()->user();
    $can = fn (string $permission): bool => (bool) $user?->can($permission);
    $canFinance = $can('payments.view') || $can('collections.view');

    $exec = $data->exec;
    $kpi = fn (string $key): ?Kpi => $exec->kpi($key);

    // --- KPI grid (permission-aware) ---------------------------------------
    $kpiCards = array_values(array_filter([
        $can('leads.view') ? ['kpi' => $kpi('total_leads'), 'icon' => 'inbox'] : null,
        $can('buyers.view') ? ['kpi' => new Kpi('active_buyers', 'Active buyers', $data->activeBuyers, 'number', hint: 'Currently active'), 'icon' => 'users'] : null,
        $can('plots.view') ? ['kpi' => $kpi('available_plots'), 'icon' => 'squares'] : null,
        $can('plots.view') ? ['kpi' => $kpi('booked_plots'), 'icon' => 'squares'] : null,
        $can('bookings.view') ? ['kpi' => $kpi('booking_value'), 'icon' => 'banknotes'] : null,
        $canFinance ? ['kpi' => $kpi('total_collected'), 'icon' => 'wallet'] : null,
        $canFinance ? ['kpi' => $kpi('outstanding'), 'icon' => 'banknotes'] : null,
        $canFinance ? ['kpi' => $kpi('overdue'), 'icon' => 'exclamation-triangle'] : null,
    ], fn ($card) => $card !== null && $card['kpi'] !== null));

    // --- Attention required ----------------------------------------------
    $attention = [];
    if ($data->followUpsVisible) {
        $attention[] = ['key' => 'fu_today', 'label' => 'Follow-ups due today', 'count' => $data->followUp('due_today'), 'tone' => 'warning', 'error' => null, 'url' => route('follow-ups.index', ['tab' => 'today'])];
        $attention[] = ['key' => 'fu_missed', 'label' => 'Missed follow-ups', 'count' => $data->followUp('missed'), 'tone' => 'danger', 'error' => null, 'url' => route('follow-ups.index', ['tab' => 'missed'])];
    }
    // Only items the user is authorised to act on (ExecutiveDashboardService
    // already nulls the url when the user lacks that item's view permission) —
    // a scope-less viewer must never see another module's pending count.
    foreach ($exec->attention as $item) {
        if ($item['url'] !== null) {
            $attention[] = $item;
        }
    }
    $attentionVisible = array_values(array_filter(
        $attention,
        fn ($item) => $item['error'] !== null || (int) ($item['count'] ?? 0) > 0,
    ));

    // --- Distributions -------------------------------------------------
    $inventoryItems = collect(PlotStatus::cases())
        ->map(fn (PlotStatus $s) => [
            'label' => $s->label(),
            'count' => $exec->inventoryDistribution[$s->value] ?? 0,
            'color' => $s->color(),
        ])->all();

    $pipelineItems = collect(LeadStatus::cases())
        ->filter(fn (LeadStatus $s) => $s->isOpen() || $s === LeadStatus::Converted)
        ->map(fn (LeadStatus $s) => [
            'label' => $s->label(),
            'count' => $data->leadPipeline[$s->value] ?? 0,
            'color' => $s === LeadStatus::Converted ? 'success' : 'brand',
        ])->values()->all();
    $pipelineLost = collect(LeadStatus::negativeOutcomes())
        ->sum(fn (LeadStatus $s) => $data->leadPipeline[$s->value] ?? 0);
    if ($pipelineLost > 0) {
        $pipelineItems[] = ['label' => 'Lost / dropped', 'count' => $pipelineLost, 'color' => 'danger'];
    }

    $metricLabel = $metric === 'value' ? 'Booking value' : 'Bookings';
@endphp

<div class="space-y-6">
    <x-ui.page-header
        title="Executive dashboard"
        :description="'King Builders Business OS · '.\Illuminate\Support\Carbon::parse($exec->generatedAt)->format('d M Y H:i')">
        <x-slot:actions>
            <select
                wire:model.live="period"
                class="rounded-lg border border-(--border) bg-(--surface) px-3 py-1.5 text-sm text-(--content) focus-brand">
                <option value="">This month</option>
                <option value="today">Today</option>
                <option value="this_week">This week</option>
                <option value="last_month">Last month</option>
                <option value="this_quarter">This quarter</option>
                <option value="this_year">This year</option>
            </select>
            <x-ui.button variant="secondary" size="sm" wire:click="$refresh">
                <span wire:loading.remove wire:target="$refresh">Refresh</span>
                <span wire:loading wire:target="$refresh">Refreshing…</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <p class="-mt-2 text-xs text-(--content-muted)">
        Period: {{ $filters->from->format('d M Y') }} – {{ $filters->to->format('d M Y') }}
    </p>

    @if ($data->hasErrors())
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p class="font-medium">Some sections could not be loaded</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach ($data->allErrors() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- Quick actions --}}
    @php
        $quickActions = array_values(array_filter([
            $can('leads.create') ? ['label' => 'New lead', 'url' => route('leads.create')] : null,
            $can('buyers.create') ? ['label' => 'New buyer', 'url' => route('buyers.create')] : null,
            $can('bookings.create') ? ['label' => 'New booking', 'url' => route('bookings.create')] : null,
            $can('payments.create') ? ['label' => 'Record payment', 'url' => route('payments.index')] : null,
            $can('follow_ups.create') ? ['label' => 'Add follow-up', 'url' => route('follow-ups.index')] : null,
            $can('reports.view') ? ['label' => 'Detailed reports', 'url' => route('reports.overview', $filters->toQueryString())] : null,
        ]));
    @endphp
    @if ($quickActions !== [])
        <div class="flex flex-wrap gap-2">
            @foreach ($quickActions as $i => $action)
                <x-ui.button
                    size="sm"
                    :variant="$i === 0 ? 'primary' : 'secondary'"
                    :href="$action['url']"
                    wire:navigate>
                    @if ($i === 0)<x-app.icon name="plus" class="size-4" />@endif
                    {{ $action['label'] }}
                </x-ui.button>
            @endforeach
        </div>
    @endif

    {{-- KPI grid --}}
    @if ($kpiCards !== [])
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($kpiCards as $card)
                <x-reports.kpi-card :kpi="$card['kpi']" :icon="$card['icon']" />
            @endforeach
        </div>
    @else
        <x-ui.card>
            <x-ui.empty-state
                title="No dashboard metrics for your role"
                description="Your account does not have access to any of the modules the executive dashboard summarises. Use the navigation to open the areas you can work with."
                icon="home" />
        </x-ui.card>
    @endif

    {{-- Attention required --}}
    @if ($attention !== [])
        <x-reports.section title="Attention required" subtitle="Actionable items across sales &amp; operations">
            @if ($attentionVisible === [])
                <x-ui.empty-state title="Nothing needs your attention right now" icon="check-circle" />
            @else
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($attentionVisible as $item)
                        <x-reports.attention-card :item="$item" />
                    @endforeach
                </div>
            @endif
        </x-reports.section>
    @endif

    {{-- Sales + Collection overview --}}
    <div class="grid gap-6 lg:grid-cols-2">
        @if ($can('bookings.view') || $can('reports.view'))
            <x-reports.section title="Sales overview"
                :subtitle="$metricLabel.' · '.ucfirst($exec->salesSeries->granularity).'ly · confirmed bookings'"
                :error="$exec->salesSeries->error"
                :empty="$exec->salesSeries->isEmpty()"
                empty-text="No confirmed bookings in this period.">
                <x-slot:actions>
                    <div class="flex rounded-lg border border-(--border) p-0.5 text-xs">
                        @foreach (['value' => 'Value', 'bookings' => 'Count'] as $m => $label)
                            <button type="button" wire:click="setMetric('{{ $m }}')"
                                @class([
                                    'rounded-md px-2 py-1 font-medium transition',
                                    'bg-(--brand-primary) text-(--brand-primary-fg)' => $metric === $m,
                                    'text-(--content-muted) hover:text-(--content)' => $metric !== $m,
                                ])>{{ $label }}</button>
                        @endforeach
                    </div>
                </x-slot:actions>

                <x-reports.bar-chart :series="$exec->salesSeries" :metric="$metric"
                    :format="$metric === 'value' ? 'currency' : 'number'" />
                <p class="mt-3 text-xs text-(--content-muted)">
                    Total {{ strtolower($metricLabel) }} in period:
                    <span class="font-medium text-(--content)">
                        {{ $metric === 'value'
                            ? ReportFormat::currency($exec->salesSeries->total('value'))
                            : ReportFormat::number($exec->salesSeries->total('bookings')) }}
                    </span>
                </p>
            </x-reports.section>
        @endif

        @if ($canFinance)
            <x-reports.section title="Collection overview"
                subtitle="M7/M8 ledger truth — no separate calculation"
                :error="$kpi('outstanding')?->error"
                :empty="$exec->collectionOverview === []">
                <dl class="space-y-3">
                    @foreach ($exec->collectionOverview as $label => $amount)
                        <div class="flex items-center justify-between text-sm">
                            <dt class="text-(--content-muted)">{{ $label }}</dt>
                            <dd class="font-semibold tabular-nums {{ str_contains($label, 'Overdue') ? 'text-red-600' : 'text-(--content)' }}">
                                {{ ReportFormat::currencyFull($amount) }}
                            </dd>
                        </div>
                    @endforeach
                </dl>
                @if ($kpi('collection_percent') && $kpi('collection_percent')->value !== null)
                    @php $pct = min(100, max(0, (float) $kpi('collection_percent')->value)); @endphp
                    <div class="mt-4">
                        <div class="flex justify-between text-xs text-(--content-muted)">
                            <span>Collected of booking value</span>
                            <span>{{ ReportFormat::percent($kpi('collection_percent')->value) }}</span>
                        </div>
                        <div class="mt-1 h-2 overflow-hidden rounded-full bg-(--surface-muted)">
                            <div class="h-full rounded-full bg-(--brand-primary)" style="width: {{ $pct }}%"></div>
                        </div>
                    </div>
                @endif
            </x-reports.section>
        @endif
    </div>

    {{-- Inventory + Lead pipeline --}}
    <div class="grid gap-6 lg:grid-cols-2">
        @if ($can('plots.view'))
            <x-reports.section title="Inventory overview" subtitle="Current plot states"
                :empty="array_sum($exec->inventoryDistribution) === 0">
                <x-reports.distribution :items="$inventoryItems" :total="array_sum($exec->inventoryDistribution)" />
            </x-reports.section>
        @endif

        @if ($data->leadPipelineVisible)
            <x-reports.section title="Lead pipeline" subtitle="Leads created in the period, by stage"
                :empty="array_sum($data->leadPipeline) === 0">
                <x-reports.distribution :items="$pipelineItems" :total="array_sum($data->leadPipeline)" />
                @php $convPct = $kpi('conversion_percent'); @endphp
                @if ($convPct && $convPct->value !== null)
                    <p class="mt-3 text-xs text-(--content-muted)">
                        Conversion rate:
                        <span class="font-medium text-(--content)">{{ ReportFormat::percent($convPct->value) }}</span>
                        · {{ ReportFormat::number($kpi('converted_leads')?->value) }} converted
                    </p>
                @endif
            </x-reports.section>
        @endif
    </div>

    {{-- Recent bookings --}}
    @if ($can('bookings.view'))
        <x-reports.section title="Recent bookings" subtitle="Latest confirmed &amp; in-progress bookings"
            :empty="$exec->recentBookings === []">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr>
                            <th class="py-2 pr-4">Booking #</th>
                            <th class="py-2 pr-4">Buyer</th>
                            <th class="py-2 pr-4">Project</th>
                            <th class="py-2 pr-4">Plot</th>
                            <th class="py-2 pr-4">Booking date</th>
                            <th class="py-2 pr-4 text-right">Amount</th>
                            <th class="py-2 pr-4">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($exec->recentBookings as $b)
                            <tr class="hover:bg-(--surface-muted)/50">
                                <td class="py-2 pr-4 font-medium">
                                    <a href="{{ route('bookings.show', $b['id']) }}" wire:navigate
                                       class="text-(--brand-primary) hover:underline">{{ $b['booking_number'] }}</a>
                                </td>
                                <td class="py-2 pr-4">{{ $b['customer'] }}</td>
                                <td class="py-2 pr-4 text-(--content-muted)">{{ $b['project'] }}</td>
                                <td class="py-2 pr-4 text-(--content-muted)">{{ $b['plot'] }}</td>
                                <td class="py-2 pr-4 text-(--content-muted)">{{ \Illuminate\Support\Carbon::parse($b['date'])->format('d M Y') }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($b['amount']) }}</td>
                                <td class="py-2 pr-4">
                                    <x-ui.badge :variant="BookingStatus::from($b['status'])->color()">{{ BookingStatus::from($b['status'])->label() }}</x-ui.badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-reports.section>
    @endif

    {{-- System health --}}
    <x-ui.card title="System health" subtitle="Live service status">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ($health as $service)
                <div class="flex items-center gap-2 rounded-lg border border-(--border) px-3 py-2 text-sm">
                    <span @class([
                        'size-2.5 shrink-0 rounded-full',
                        'bg-emerald-500' => $service['ok'],
                        'bg-red-500' => ! $service['ok'],
                    ])></span>
                    <span class="font-medium text-(--content)">{{ $service['label'] }}</span>
                    <span class="ml-auto text-xs text-(--content-muted)">{{ $service['ok'] ? 'OK' : 'Down' }}</span>
                </div>
            @endforeach
        </div>
    </x-ui.card>
</div>
