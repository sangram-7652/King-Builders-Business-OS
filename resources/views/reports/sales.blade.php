@php
    use App\Support\Reports\ReportFormat;

    /** @var array{title:string,description:string,milestone:string,route:string} $meta */
    /** @var array<string, array<string,string>> $reports */
    /** @var \App\Support\Reports\ReportFilterData $filters */
    /** @var \App\Support\Reports\FilterOptions $options */
    /** @var \App\Support\Reports\SalesReportData $sales */
    /** @var bool $canExport */

    $qs = $filters->toQueryString();
    $metricLabel = $sales->trendMetric === 'value' ? 'Booking value' : 'Bookings';

    $spColumns = ['value' => 'Value', 'bookings' => 'Bookings'];
    $spLink = fn (string $col) => route('reports.sales', $qs + ['sp_sort' => $col, 'metric' => $sales->trendMetric]);
@endphp

<x-layouts.app :title="$meta['title']" :breadcrumbs="[['label' => 'Reports', 'url' => route('reports.overview')], ['label' => 'Sales']]">

    <x-ui.page-header :title="$meta['title']" :description="$meta['description']">
        <x-slot:actions>
            <x-ui.badge variant="muted">{{ $sales->statusLabel }} bookings</x-ui.badge>
        </x-slot:actions>
    </x-ui.page-header>

    <x-reports.tabs :reports="$reports" :active="$report" :filters="$filters" />

    <x-reports.filters :filters="$filters" :options="$options" :action="route('reports.sales')" :can-export="$canExport" :report="$report"
        :only="['period', 'project_id', 'block_id', 'salesperson_id', 'booking_status']" />

    @if ($sales->hasErrors())
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p class="font-medium">Some sections could not be loaded</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach ($sales->errors as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- KPIs --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach (['total_bookings', 'booking_value', 'avg_booking_value', 'booking_growth'] as $key)
            <x-reports.kpi-card :kpi="$sales->kpi($key)" />
        @endforeach
    </div>

    {{-- Trend + velocity --}}
    <div class="grid gap-6 lg:grid-cols-3">
        <x-reports.section title="Sales trend" :subtitle="$metricLabel.' · '.ucfirst($sales->trend->granularity).'ly'" class="lg:col-span-2"
            :error="$sales->trend->error" :empty="$sales->trend->isEmpty()" empty-text="No bookings in this period.">
            <x-slot:actions>
                <div class="flex rounded-lg border border-(--border) p-0.5 text-xs">
                    @foreach (['value' => 'Value', 'bookings' => 'Count'] as $m => $label)
                        <a href="{{ route('reports.sales', $qs + ['metric' => $m, 'sp_sort' => $sales->salespeopleSort]) }}"
                           @class([
                               'rounded-md px-2 py-1 font-medium',
                               'bg-(--brand-primary) text-(--brand-primary-fg)' => $sales->trendMetric === $m,
                               'text-(--content-muted)' => $sales->trendMetric !== $m,
                           ])>{{ $label }}</a>
                    @endforeach
                </div>
            </x-slot:actions>

            <x-reports.bar-chart :series="$sales->trend" :metric="$sales->trendMetric"
                :format="$sales->trendMetric === 'value' ? 'currency' : 'number'" />
        </x-reports.section>

        <x-reports.section title="Booking velocity" :subtitle="$sales->velocity['days'].'-day window'"
            :error="$sales->error('Booking velocity')">
            <dl class="space-y-3">
                <div class="flex items-baseline justify-between">
                    <dt class="text-sm text-(--content-muted)">Bookings / day</dt>
                    <dd class="text-2xl font-semibold tabular-nums">{{ number_format($sales->velocity['per_day'], 2) }}</dd>
                </div>
                <div class="flex items-baseline justify-between">
                    <dt class="text-sm text-(--content-muted)">Bookings / week</dt>
                    <dd class="text-2xl font-semibold tabular-nums">{{ number_format($sales->velocity['per_week'], 2) }}</dd>
                </div>
                <div class="flex items-baseline justify-between border-t border-(--border) pt-2 text-xs text-(--content-muted)">
                    <dt>Total in window</dt>
                    <dd class="tabular-nums">{{ number_format($sales->velocity['bookings']) }}</dd>
                </div>
            </dl>
        </x-reports.section>
    </div>

    {{-- Project-wise sales --}}
    <x-reports.section title="Project-wise sales" :error="$sales->error('Project-wise sales')" :empty="$sales->projectSales === []">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-(--border) text-sm">
                <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                    <tr>
                        <th class="py-2 pr-4">Project</th>
                        <th class="py-2 pr-4 text-right">Bookings</th>
                        <th class="py-2 pr-4 text-right">Value</th>
                        <th class="py-2 pr-4 text-right">Avg value</th>
                        <th class="py-2 pr-4 text-right">Sold %</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-(--border)">
                    @foreach ($sales->projectSales as $row)
                        <tr class="hover:bg-(--surface-muted)/50">
                            <td class="py-2 pr-4 font-medium">
                                @can('projects.view')
                                    <a href="{{ route('projects.show', $row['project_id']) }}" wire:navigate class="text-(--brand-primary) hover:underline">{{ $row['project'] }}</a>
                                @else {{ $row['project'] }} @endcan
                            </td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['bookings']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($row['value']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($row['average']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::percent($row['sold_pct']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-reports.section>

    {{-- Block-wise sales --}}
    <x-reports.section title="Block-wise sales" :error="$sales->error('Block-wise sales')" :empty="$sales->blockSales === []">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-(--border) text-sm">
                <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                    <tr>
                        <th class="py-2 pr-4">Block</th>
                        <th class="py-2 pr-4 text-right">Inventory</th>
                        <th class="py-2 pr-4 text-right">Booked</th>
                        <th class="py-2 pr-4 text-right">Available</th>
                        <th class="py-2 pr-4 text-right">Value</th>
                        <th class="py-2 pr-4 text-right">Sold %</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-(--border)">
                    @foreach ($sales->blockSales as $row)
                        <tr class="hover:bg-(--surface-muted)/50">
                            <td class="py-2 pr-4 font-medium">
                                @can('plots.view')
                                    <a href="{{ route('plots.index', ['project' => $row['project_id'], 'block' => $row['block_id']]) }}" wire:navigate class="text-(--brand-primary) hover:underline">{{ $row['block'] }}</a>
                                @else {{ $row['block'] }} @endcan
                                <span class="text-xs text-(--content-muted)">· {{ $row['project'] }}</span>
                            </td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['total']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['booked']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['available']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($row['value']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::percent($row['sold_pct']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-reports.section>

    {{-- Salesperson performance --}}
    <x-reports.section title="Salesperson performance"
        :subtitle="'Sorted by '.strtolower($spColumns[$sales->salespeopleSort])"
        :error="$sales->error('Salesperson performance')" :empty="$sales->salespeople === []">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-(--border) text-sm">
                <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                    <tr>
                        <th class="py-2 pr-4">Salesperson</th>
                        @foreach ($spColumns as $col => $label)
                            <th class="py-2 pr-4 text-right">
                                <a href="{{ $spLink($col) }}" class="hover:text-(--content) {{ $sales->salespeopleSort === $col ? 'text-(--brand-primary)' : '' }}">
                                    {{ $label }} {!! $sales->salespeopleSort === $col ? '▾' : '' !!}
                                </a>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-(--border)">
                    @foreach ($sales->salespeople as $row)
                        <tr class="hover:bg-(--surface-muted)/50">
                            <td class="py-2 pr-4 font-medium">{{ $row['name'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($row['value']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['bookings']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-reports.section>

</x-layouts.app>
