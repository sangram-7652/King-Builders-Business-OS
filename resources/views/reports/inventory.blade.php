@php
    use App\Enums\AgingBucket;
    use App\Enums\PlotStatus;
    use App\Support\Reports\ReportFormat;

    /** @var array{title:string,description:string,milestone:string,route:string} $meta */
    /** @var array<string, array<string,string>> $reports */
    /** @var \App\Support\Reports\ReportFilterData $filters */
    /** @var \App\Support\Reports\InventoryFilters $extras */
    /** @var \App\Support\Reports\FilterOptions $options */
    /** @var \App\Support\Reports\InventoryReportData $inventory */
    /** @var bool $canExport */

    $distItems = collect(PlotStatus::cases())->map(fn (PlotStatus $s) => [
        'label' => $s->label(),
        'count' => $inventory->distribution[$s->value] ?? 0,
        'color' => $s->color(),
    ])->all();

    $ageItems = collect(AgingBucket::cases())->map(fn (AgingBucket $b) => [
        'label' => $b->label(),
        'count' => $inventory->ageing[$b->value] ?? 0,
        'color' => $b->color(),
    ])->all();
@endphp

<x-layouts.app :title="$meta['title']" :breadcrumbs="[['label' => 'Reports', 'url' => route('reports.overview')], ['label' => 'Inventory']]">

    <x-ui.page-header :title="$meta['title']" :description="$meta['description']" />

    <x-reports.tabs :reports="$reports" :active="$report" :filters="$filters" />

    <x-reports.filters :filters="$filters" :options="$options" :action="route('reports.inventory')" :can-export="$canExport" :report="$report"
        :extras="$extras" :only="['project_id', 'block_id', 'plot_status', 'size_range', 'price_range']" />

    @if ($inventory->hasErrors())
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p class="font-medium">Some sections could not be loaded</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach ($inventory->errors as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- KPIs --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
        @foreach (['total_inventory', 'available', 'booked', 'registered', 'possession_completed'] as $key)
            <x-reports.kpi-card :kpi="$inventory->kpi($key)" />
        @endforeach
    </div>

    {{-- Status distribution + ageing --}}
    <div class="grid gap-6 lg:grid-cols-2">
        <x-reports.section title="Inventory status" subtitle="Current plot states (M4)" :error="$inventory->error('Status distribution')">
            <x-reports.distribution :items="$distItems" :total="array_sum($inventory->distribution)" />
        </x-reports.section>

        <x-reports.section title="Inventory ageing" subtitle="Available plots by time in inventory"
            :error="$inventory->error('Inventory ageing')" :empty="array_sum($inventory->ageing) === 0"
            empty-text="No available plots to age.">
            <x-reports.distribution :items="$ageItems" :total="array_sum($inventory->ageing)" />
        </x-reports.section>
    </div>

    {{-- Project inventory --}}
    <x-reports.section title="Project inventory" :error="$inventory->error('Project inventory')" :empty="$inventory->projectInventory === []">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-(--border) text-sm">
                <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                    <tr>
                        <th class="py-2 pr-4">Project</th>
                        <th class="py-2 pr-4 text-right">Total</th>
                        <th class="py-2 pr-4 text-right">Available</th>
                        <th class="py-2 pr-4 text-right">Booked</th>
                        <th class="py-2 pr-4 text-right">Registered</th>
                        <th class="py-2 pr-4 text-right">Possession</th>
                        <th class="py-2 pr-4 text-right">Sold %</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-(--border)">
                    @foreach ($inventory->projectInventory as $row)
                        <tr class="hover:bg-(--surface-muted)/50">
                            <td class="py-2 pr-4 font-medium">
                                @can('projects.view')
                                    <a href="{{ route('projects.show', $row['project_id']) }}" wire:navigate class="text-(--brand-primary) hover:underline">{{ $row['project'] }}</a>
                                @else {{ $row['project'] }} @endcan
                            </td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['total']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['available']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['booked']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['registered']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['possession']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::percent($row['sold_pct']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-reports.section>

    {{-- Block inventory --}}
    <x-reports.section title="Block inventory" :error="$inventory->error('Block inventory')" :empty="$inventory->blockInventory === []">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-(--border) text-sm">
                <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                    <tr>
                        <th class="py-2 pr-4">Block</th>
                        <th class="py-2 pr-4 text-right">Total</th>
                        <th class="py-2 pr-4 text-right">Available</th>
                        <th class="py-2 pr-4 text-right">Booked</th>
                        <th class="py-2 pr-4 text-right">Registered</th>
                        <th class="py-2 pr-4 text-right">Possession</th>
                        <th class="py-2 pr-4 text-right">Sold %</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-(--border)">
                    @foreach ($inventory->blockInventory as $row)
                        <tr class="hover:bg-(--surface-muted)/50">
                            <td class="py-2 pr-4 font-medium">
                                @can('plots.view')
                                    <a href="{{ route('plots.index', ['project' => $row['project_id'], 'block' => $row['block_id']]) }}" wire:navigate class="text-(--brand-primary) hover:underline">{{ $row['block'] }}</a>
                                @else {{ $row['block'] }} @endcan
                                <span class="text-xs text-(--content-muted)">· {{ $row['project'] }}</span>
                            </td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['total']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['available']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['booked']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['registered']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['possession']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::percent($row['sold_pct']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-reports.section>

    {{-- Price + size analytics --}}
    <div class="grid gap-6 lg:grid-cols-2">
        <x-reports.section title="Price bands" subtitle="From confirmed bookings (available plots are priced at booking)"
            :error="$inventory->error('Price analytics')" :empty="! $inventory->priceSupported"
            empty-text="No confirmed bookings to derive price bands.">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr><th class="py-2 pr-4">Band</th><th class="py-2 pr-4 text-right">Bookings</th><th class="py-2 pr-4 text-right">Value</th><th class="py-2 pr-4 text-right">Avg price</th></tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($inventory->priceBands as $row)
                            <tr>
                                <td class="py-2 pr-4">{{ $row['band'] }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['bookings']) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($row['value']) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($row['average']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-reports.section>

        <x-reports.section title="Size bands" subtitle="Plots by size master" :error="$inventory->error('Size analytics')"
            :empty="$inventory->sizeBands === []">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr><th class="py-2 pr-4">Size</th><th class="py-2 pr-4 text-right">Total</th><th class="py-2 pr-4 text-right">Available</th><th class="py-2 pr-4 text-right">Booked</th></tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($inventory->sizeBands as $row)
                            <tr>
                                <td class="py-2 pr-4">{{ $row['band'] }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['total']) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['available']) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['booked']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-reports.section>
    </div>

    {{-- Available inventory list --}}
    <x-reports.section :title="\Illuminate\Support\Str::headline(($filters->plotStatus?->value ?? 'available')).' plots'"
        :subtitle="$inventory->availablePlots->total().' plots'"
        :error="$inventory->error('Available plots')" :empty="$inventory->availablePlots->isEmpty()">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-(--border) text-sm">
                <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                    <tr><th class="py-2 pr-4">Plot</th><th class="py-2 pr-4">Block</th><th class="py-2 pr-4">Size</th><th class="py-2 pr-4 text-right">Area</th><th class="py-2 pr-4">Status</th></tr>
                </thead>
                <tbody class="divide-y divide-(--border)">
                    @foreach ($inventory->availablePlots as $plot)
                        <tr class="hover:bg-(--surface-muted)/50">
                            <td class="py-2 pr-4 font-medium">
                                @can('plots.view')
                                    <a href="{{ route('plots.show', ['project' => $plot->project_id, 'block' => $plot->block_id, 'plot' => $plot->id]) }}" wire:navigate class="text-(--brand-primary) hover:underline">{{ $plot->plot_number }}</a>
                                @else {{ $plot->plot_number }} @endcan
                            </td>
                            <td class="py-2 pr-4">{{ $plot->block?->name ?? '—' }}</td>
                            <td class="py-2 pr-4">{{ $plot->size?->name ?? '—' }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ $plot->areaLabel() }}</td>
                            <td class="py-2 pr-4"><x-ui.badge :variant="$plot->status->color()">{{ $plot->status->label() }}</x-ui.badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($inventory->availablePlots->hasPages())
            <div class="mt-3">{{ $inventory->availablePlots->onEachSide(1)->links() }}</div>
        @endif
    </x-reports.section>

</x-layouts.app>
