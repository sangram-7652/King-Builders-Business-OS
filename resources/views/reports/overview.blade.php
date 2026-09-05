@php
    use App\Enums\BookingStatus;
    use App\Enums\PlotStatus;
    use App\Support\Reports\ReportFormat;

    /** @var array{title:string,description:string,milestone:string,route:string} $meta */
    /** @var array<string, array<string,string>> $reports */
    /** @var \App\Support\Reports\ReportFilterData $filters */
    /** @var \App\Support\Reports\FilterOptions $options */
    /** @var \App\Support\Reports\ExecutiveDashboardData $dashboard */
    /** @var bool $canExport */

    $kpi = fn (string $key) => $dashboard->kpi($key);

    $inventoryItems = collect(PlotStatus::cases())->map(fn (PlotStatus $s) => [
        'label' => $s->label(),
        'count' => $dashboard->inventoryDistribution[$s->value] ?? 0,
        'color' => $s->color(),
    ])->all();

    $bookingItems = collect(BookingStatus::cases())->map(fn (BookingStatus $s) => [
        'label' => $s->label(),
        'count' => $dashboard->bookingStatusDistribution[$s->value] ?? 0,
        'color' => $s->color(),
    ])->all();

    $metricLabel = $dashboard->salesMetric === 'value' ? 'Booking value' : 'Bookings';
@endphp

<x-layouts.app :title="$meta['title']" :breadcrumbs="[['label' => 'Reports', 'url' => route('reports.overview')], ['label' => 'Overview']]">

    <x-ui.page-header :title="$meta['title']" :description="$meta['description']">
        <x-slot:actions>
            <span class="text-xs text-(--content-muted)">Generated {{ \Illuminate\Support\Carbon::parse($dashboard->generatedAt)->format('d M Y H:i') }}</span>
        </x-slot:actions>
    </x-ui.page-header>

    <x-reports.tabs :reports="$reports" :active="$report" :filters="$filters" />

    <x-reports.filters :filters="$filters" :options="$options" :action="route('reports.overview')" :can-export="$canExport" />

    @if ($dashboard->hasErrors())
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p class="font-medium">Some sections could not be loaded</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach ($dashboard->errors as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- Quick actions --}}
    <div class="flex flex-wrap gap-2">
        @can('bookings.create')
            <x-ui.button size="sm" :href="route('bookings.create')" wire:navigate>New booking</x-ui.button>
        @endcan
        @can('leads.create')
            <x-ui.button size="sm" variant="secondary" :href="route('leads.create')" wire:navigate>Add lead</x-ui.button>
        @endcan
        @can('collections.view')
            <x-ui.button size="sm" variant="secondary" :href="route('collections.queue')" wire:navigate>Record collection</x-ui.button>
        @endcan
        <x-ui.button size="sm" variant="ghost" :href="route('reports.sales', $filters->toQueryString())">Detailed reports</x-ui.button>
    </div>

    {{-- KPI grid — 4/row desktop · 2/row tablet · 1/row mobile --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            'total_projects', 'total_plots', 'available_plots', 'booked_plots',
            'total_bookings', 'booking_value', 'total_collected', 'outstanding',
            'overdue', 'collection_percent', 'total_leads', 'converted_leads',
            'conversion_percent', 'registry_pending', 'possession_pending', 'transfer_pending',
        ] as $key)
            @if ($kpi($key))
                <x-reports.kpi-card :kpi="$kpi($key)" />
            @endif
        @endforeach
    </div>

    {{-- Sales + Collection overview --}}
    <div class="grid gap-6 lg:grid-cols-2">
        <x-reports.section title="Sales overview" :subtitle="$metricLabel.' · '.ucfirst($dashboard->salesSeries->granularity).'ly'"
            :error="$dashboard->salesSeries->error"
            :empty="$dashboard->salesSeries->isEmpty()"
            empty-text="No confirmed bookings in this period.">
            <x-slot:actions>
                <div class="flex rounded-lg border border-(--border) p-0.5 text-xs">
                    @foreach (['value' => 'Value', 'bookings' => 'Count'] as $m => $label)
                        <a href="{{ route('reports.overview', $filters->toQueryString() + ['metric' => $m]) }}"
                           @class([
                               'rounded-md px-2 py-1 font-medium',
                               'bg-(--brand-primary) text-(--brand-primary-fg)' => $dashboard->salesMetric === $m,
                               'text-(--content-muted)' => $dashboard->salesMetric !== $m,
                           ])>{{ $label }}</a>
                    @endforeach
                </div>
            </x-slot:actions>

            <x-reports.bar-chart :series="$dashboard->salesSeries" :metric="$dashboard->salesMetric"
                :format="$dashboard->salesMetric === 'value' ? 'currency' : 'number'" />
            <p class="mt-3 text-xs text-(--content-muted)">
                Total {{ strtolower($metricLabel) }} in period:
                <span class="font-medium text-(--content)">
                    {{ $dashboard->salesMetric === 'value'
                        ? ReportFormat::currency($dashboard->salesSeries->total('value'))
                        : ReportFormat::number($dashboard->salesSeries->total('bookings')) }}
                </span>
            </p>
        </x-reports.section>

        <x-reports.section title="Collection overview" subtitle="Reuses the M7/M8 ledger — no separate calculation"
            :error="$dashboard->kpi('outstanding')?->error"
            :empty="$dashboard->collectionOverview === []">
            <dl class="space-y-3">
                @foreach ($dashboard->collectionOverview as $label => $amount)
                    <div class="flex items-center justify-between text-sm">
                        <dt class="text-(--content-muted)">{{ $label }}</dt>
                        <dd class="font-semibold tabular-nums {{ str_contains($label, 'Overdue') ? 'text-red-600' : 'text-(--content)' }}">
                            {{ ReportFormat::currencyFull($amount) }}
                        </dd>
                    </div>
                @endforeach
            </dl>
            @if ($dashboard->kpi('collection_percent') && $dashboard->kpi('collection_percent')->value !== null)
                @php $pct = min(100, max(0, (float) $dashboard->kpi('collection_percent')->value)); @endphp
                <div class="mt-4">
                    <div class="flex justify-between text-xs text-(--content-muted)">
                        <span>Collected of booking value</span>
                        <span>{{ ReportFormat::percent($dashboard->kpi('collection_percent')->value) }}</span>
                    </div>
                    <div class="mt-1 h-2 overflow-hidden rounded-full bg-(--surface-muted)">
                        <div class="h-full rounded-full bg-(--brand-primary)" style="width: {{ $pct }}%"></div>
                    </div>
                </div>
            @endif
        </x-reports.section>
    </div>

    {{-- Inventory + Booking status --}}
    <div class="grid gap-6 lg:grid-cols-2">
        <x-reports.section title="Inventory status" subtitle="Current plot states (M4)">
            <x-reports.distribution :items="$inventoryItems" :total="array_sum($dashboard->inventoryDistribution)" />
        </x-reports.section>

        <x-reports.section title="Booking status" subtitle="Bookings in the selected period (M6)"
            :empty="array_sum($dashboard->bookingStatusDistribution) === 0">
            <x-reports.distribution :items="$bookingItems" :total="array_sum($dashboard->bookingStatusDistribution)" />
        </x-reports.section>
    </div>

    {{-- Project performance --}}
    <x-reports.section title="Project performance" subtitle="Bookings/value in period · collected & outstanding are current"
        :empty="$dashboard->projectPerformance === []">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-(--border) text-sm">
                <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                    <tr>
                        <th class="py-2 pr-4">Project</th>
                        <th class="py-2 pr-4 text-right">Inventory</th>
                        <th class="py-2 pr-4 text-right">Booked</th>
                        <th class="py-2 pr-4 text-right">Value</th>
                        <th class="py-2 pr-4 text-right">Collected</th>
                        <th class="py-2 pr-4 text-right">Outstanding</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-(--border)">
                    @foreach ($dashboard->projectPerformance as $row)
                        <tr class="hover:bg-(--surface-muted)/50">
                            <td class="py-2 pr-4 font-medium">
                                @can('projects.view')
                                    <a href="{{ route('projects.show', $row['project_id']) }}" wire:navigate class="text-(--brand-primary) hover:underline">{{ $row['project'] }}</a>
                                @else
                                    {{ $row['project'] }}
                                @endcan
                            </td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['plots']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['booked']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($row['value']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($row['collected']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums {{ $row['outstanding'] > 0 ? 'text-red-600' : '' }}">{{ ReportFormat::currency($row['outstanding']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-reports.section>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Top salespeople --}}
        @if ($dashboard->topSalespeople !== null)
            <x-reports.section title="Top salespeople" subtitle="By confirmed booking value in period"
                :empty="$dashboard->topSalespeople === []">
                <ol class="space-y-2">
                    @foreach ($dashboard->topSalespeople as $i => $sp)
                        <li class="flex items-center justify-between gap-3 text-sm">
                            <span class="flex items-center gap-2">
                                <span class="flex size-5 items-center justify-center rounded-full bg-(--surface-muted) text-[11px] font-semibold">{{ $i + 1 }}</span>
                                {{ $sp['name'] }}
                            </span>
                            <span class="text-right">
                                <span class="font-semibold tabular-nums">{{ ReportFormat::currency($sp['value']) }}</span>
                                <span class="ml-1 text-xs text-(--content-muted)">· {{ $sp['bookings'] }} bkg</span>
                            </span>
                        </li>
                    @endforeach
                </ol>
            </x-reports.section>
        @endif

        {{-- Attention required --}}
        <x-reports.section title="Attention required" subtitle="Operational items across M8–M10">
            <div class="space-y-2">
                @foreach ($dashboard->attention as $item)
                    <x-reports.attention-card :item="$item" />
                @endforeach
            </div>
        </x-reports.section>
    </div>

    {{-- Recent bookings --}}
    <x-reports.section title="Recent bookings" :empty="$dashboard->recentBookings === []">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-(--border) text-sm">
                <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                    <tr>
                        <th class="py-2 pr-4">Booking</th>
                        <th class="py-2 pr-4">Customer</th>
                        <th class="py-2 pr-4">Plot</th>
                        <th class="py-2 pr-4">Project</th>
                        <th class="py-2 pr-4 text-right">Amount</th>
                        <th class="py-2 pr-4">Date</th>
                        <th class="py-2 pr-4">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-(--border)">
                    @foreach ($dashboard->recentBookings as $b)
                        <tr class="hover:bg-(--surface-muted)/50">
                            <td class="py-2 pr-4 font-medium">
                                @can('bookings.view')
                                    <a href="{{ route('bookings.show', $b['id']) }}" wire:navigate class="text-(--brand-primary) hover:underline">{{ $b['booking_number'] }}</a>
                                @else
                                    {{ $b['booking_number'] }}
                                @endcan
                            </td>
                            <td class="py-2 pr-4">{{ $b['customer'] }}</td>
                            <td class="py-2 pr-4">{{ $b['plot'] }}</td>
                            <td class="py-2 pr-4">{{ $b['project'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($b['amount']) }}</td>
                            <td class="py-2 pr-4 text-(--content-muted)">{{ \Illuminate\Support\Carbon::parse($b['date'])->format('d M Y') }}</td>
                            <td class="py-2 pr-4">
                                <x-ui.badge :variant="\App\Enums\BookingStatus::from($b['status'])->color()">{{ \App\Enums\BookingStatus::from($b['status'])->label() }}</x-ui.badge>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-reports.section>

</x-layouts.app>
