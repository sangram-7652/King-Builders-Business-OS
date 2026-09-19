@php
    use App\Support\Reports\ReportFormat;

    /** @var array{title:string,description:string,milestone:string,route:string} $meta */
    /** @var array<string, array<string,string>> $reports */
    /** @var \App\Support\Reports\ReportFilterData $filters */
    /** @var \App\Support\Reports\FilterOptions $options */
    /** @var \App\Support\Reports\MisReportData $mis */
    /** @var bool $canExport */

    $th = 'py-2 pr-4 text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)';
    $thr = 'py-2 pr-4 text-right text-xs font-semibold uppercase tracking-wider text-(--content-muted)';
    $td = 'py-2 pr-4 tabular-nums';
@endphp

<x-layouts.app :title="$meta['title']" :breadcrumbs="[['label' => 'Reports', 'url' => route('reports.overview')], ['label' => 'MIS']]">

    <x-ui.page-header :title="$meta['title']" :description="$meta['description']">
        <x-slot:actions>
            <span class="text-xs text-(--content-muted)">Generated {{ \Illuminate\Support\Carbon::parse($mis->generatedAt)->format('d M Y H:i') }}</span>
        </x-slot:actions>
    </x-ui.page-header>

    <x-reports.tabs :reports="$reports" :active="$report" :filters="$filters" />

    <x-reports.filters :filters="$filters" :options="$options" :action="route('reports.mis')" :can-export="$canExport" :report="$report"
        :only="['period', 'project_id', 'block_id', 'salesperson_id', 'booking_status', 'payment_status', 'plot_status']" />

    @if ($mis->hasErrors())
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p class="font-medium">Some sections could not be loaded</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach ($mis->errors as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <p class="text-xs text-(--content-muted)">
        Consolidated management figures. Financials are M7 truth — outstanding is booking final amount minus
        successful payments (never a separate engine). Snapshot KPIs cover the whole book; the daily / monthly
        tables are driven by the selected date range.
    </p>

    {{-- KPI grid --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        @foreach ([
            'total_projects', 'total_plots', 'available', 'booked', 'registered',
            'possession_completed', 'total_bookings', 'booking_value', 'collected',
            'outstanding',
            'registry_pending', 'possession_pending', 'transfer_pending', 'documents_pending',
        ] as $key)
            <x-reports.kpi-card :kpi="$mis->kpi($key)" />
        @endforeach
    </div>

    {{-- Project MIS --}}
    <x-reports.section title="Project MIS" :error="$mis->error('Project MIS')" :empty="$mis->projects === []">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-(--border) text-sm">
                <thead>
                    <tr>
                        <th class="{{ $th }}">Project</th>
                        <th class="{{ $thr }}">Plots</th>
                        <th class="{{ $thr }}">Booked</th>
                        <th class="{{ $thr }}">Available</th>
                        <th class="{{ $thr }}">Sales value</th>
                        <th class="{{ $thr }}">Collected</th>
                        <th class="{{ $thr }}">Outstanding</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-(--border)">
                    @foreach ($mis->projects as $row)
                        <tr class="hover:bg-(--surface-muted)/50">
                            <td class="py-2 pr-4 font-medium">
                                @can('projects.view')
                                    <a href="{{ route('projects.show', $row['project_id']) }}" wire:navigate class="text-(--brand-primary) hover:underline">{{ $row['project'] }}</a>
                                @else {{ $row['project'] }} @endcan
                            </td>
                            <td class="{{ $td }} text-right">{{ ReportFormat::number($row['plots']) }}</td>
                            <td class="{{ $td }} text-right">{{ ReportFormat::number($row['booked']) }}</td>
                            <td class="{{ $td }} text-right">{{ ReportFormat::number($row['available']) }}</td>
                            <td class="{{ $td }} text-right">{{ ReportFormat::currency($row['sales_value']) }}</td>
                            <td class="{{ $td }} text-right">{{ ReportFormat::currency($row['collected']) }}</td>
                            <td class="{{ $td }} text-right">{{ ReportFormat::currency($row['outstanding']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-reports.section>

    {{-- Salesperson MIS --}}
    <x-reports.section title="Salesperson MIS" subtitle="Booking attribution + M7 payments"
        :error="$mis->error('Salesperson MIS')" :empty="$mis->salespeople === []">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-(--border) text-sm">
                <thead>
                    <tr>
                        <th class="{{ $th }}">Salesperson</th>
                        <th class="{{ $thr }}">Bookings</th>
                        <th class="{{ $thr }}">Booking value</th>
                        <th class="{{ $thr }}">Collected</th>
                        <th class="{{ $thr }}">Outstanding</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-(--border)">
                    @foreach ($mis->salespeople as $row)
                        <tr class="hover:bg-(--surface-muted)/50">
                            <td class="py-2 pr-4 font-medium">{{ $row['name'] }}</td>
                            <td class="{{ $td }} text-right">{{ ReportFormat::number($row['bookings']) }}</td>
                            <td class="{{ $td }} text-right">{{ ReportFormat::currency($row['booking_value']) }}</td>
                            <td class="{{ $td }} text-right">{{ ReportFormat::currency($row['collected']) }}</td>
                            <td class="{{ $td }} text-right">{{ ReportFormat::currency($row['outstanding']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-reports.section>

    {{-- Monthly MIS --}}
    <x-reports.section title="Monthly MIS" :error="$mis->error('Monthly MIS')" :empty="$mis->monthly === []">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-(--border) text-sm">
                <thead>
                    <tr>
                        <th class="{{ $th }}">Month</th>
                        <th class="{{ $thr }}">Bookings</th>
                        <th class="{{ $thr }}">Booking value</th>
                        <th class="{{ $thr }}">Collected</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-(--border)">
                    @foreach ($mis->monthly as $row)
                        <tr>
                            <td class="py-2 pr-4 font-medium">{{ $row['month'] }}</td>
                            <td class="{{ $td }} text-right">{{ ReportFormat::number($row['bookings']) }}</td>
                            <td class="{{ $td }} text-right">{{ ReportFormat::currency($row['booking_value']) }}</td>
                            <td class="{{ $td }} text-right">{{ ReportFormat::currency($row['collected']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-reports.section>

    {{-- Daily MIS --}}
    <x-reports.section title="Daily MIS"
        :subtitle="$mis->dailyTruncated ? 'Most recent '.\App\Services\Reports\MisAnalytics::MAX_DAILY_ROWS.' days — export for the full window' : 'One row per day in the selected window'"
        :error="$mis->error('Daily MIS')" :empty="$mis->daily === []">
        <div class="max-h-96 overflow-auto">
            <table class="min-w-full divide-y divide-(--border) text-sm">
                <thead class="sticky top-0 bg-(--surface)">
                    <tr>
                        <th class="{{ $th }}">Date</th>
                        <th class="{{ $thr }}">Bookings</th>
                        <th class="{{ $thr }}">Booking value</th>
                        <th class="{{ $thr }}">Collected</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-(--border)">
                    @foreach ($mis->daily as $row)
                        <tr>
                            <td class="py-2 pr-4">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d M Y') }}</td>
                            <td class="{{ $td }} text-right">{{ ReportFormat::number($row['bookings']) }}</td>
                            <td class="{{ $td }} text-right">{{ ReportFormat::currency($row['booking_value']) }}</td>
                            <td class="{{ $td }} text-right">{{ ReportFormat::currency($row['collected']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-reports.section>

</x-layouts.app>
