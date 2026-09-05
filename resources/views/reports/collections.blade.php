@php
    use App\Enums\AgingBucket;
    use App\Support\Reports\ReportFormat;

    /** @var array{title:string,description:string,milestone:string,route:string} $meta */
    /** @var array<string, array<string,string>> $reports */
    /** @var \App\Support\Reports\ReportFilterData $filters */
    /** @var \App\Support\Reports\CollectionFilters $extras */
    /** @var \App\Support\Reports\FilterOptions $options */
    /** @var \App\Support\Reports\CollectionReportData $collections */
    /** @var bool $canExport */

    $qs = $filters->toQueryString() + $extras->toQueryString();

    // Ageing distribution rows (M8 buckets).
    $ageItems = collect($collections->ageing)->map(fn (array $r) => [
        'label' => $r['label'],
        'count' => (int) round($r['outstanding']),
        'color' => AgingBucket::from($r['bucket'])->color(),
    ])->all();
    $ageTotal = (int) round(collect($collections->ageing)->sum('outstanding'));

    // Buyer full name from a recovery row (stdClass, no model helper).
    $buyerName = fn ($r) => trim(implode(' ', array_filter([
        $r->first_name ?? null, $r->middle_name ?? null, $r->last_name ?? null,
    ]))) ?: '—';

    $recoverySortLink = fn (string $col) => route('reports.collections', $qs + ['recovery_sort' => $col]);
    $sortCaret = fn (string $col) => $collections->recoverySort === $col ? ' ▾' : '';
@endphp

<x-layouts.app :title="$meta['title']" :breadcrumbs="[['label' => 'Reports', 'url' => route('reports.overview')], ['label' => 'Collections']]">

    <x-ui.page-header :title="$meta['title']" :description="$meta['description']" />

    <x-reports.tabs :reports="$reports" :active="$report" :filters="$filters" />

    <x-reports.filters :filters="$filters" :options="$options" :action="route('reports.collections')" :can-export="$canExport" :report="$report"
        :extras="$extras"
        :only="['period', 'project_id', 'block_id', 'salesperson_id', 'payment_status', 'ageing_bucket', 'payment_method']" />

    @if ($collections->hasErrors())
        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p class="font-medium">Some sections could not be loaded</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach ($collections->errors as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <p class="text-xs text-(--content-muted)">
        Every figure below is M7/M8 financial truth — receivable is demand raised on the live payment plan,
        outstanding is the M8 installment walk, cheque bounces are excluded from collected money. KPIs reflect
        the whole book; the date range drives the trend, monthly and payment-method sections.
    </p>

    {{-- KPIs --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
        @foreach (['receivable', 'collected', 'outstanding', 'overdue', 'efficiency', 'cash_collected'] as $key)
            <x-reports.kpi-card :kpi="$collections->kpi($key)" />
        @endforeach
    </div>

    {{-- Collection + efficiency trend --}}
    <div class="grid gap-6 lg:grid-cols-3">
        <x-reports.section title="Collection trend" :subtitle="'Cash collected · '.ucfirst($collections->trend->granularity).'ly'"
            class="lg:col-span-2" :error="$collections->trend->error" :empty="$collections->trend->isEmpty()"
            empty-text="No collection activity in this period.">
            <x-reports.bar-chart :series="$collections->trend" metric="collected" format="currency" />
            <div class="mt-3 flex flex-wrap gap-x-6 gap-y-1 border-t border-(--border) pt-3 text-xs text-(--content-muted)">
                <span>Receivable due in window: <span class="font-medium text-(--content) tabular-nums">{{ ReportFormat::currency($collections->trend->total('receivable')) }}</span></span>
                <span>Collected in window: <span class="font-medium text-(--content) tabular-nums">{{ ReportFormat::currency($collections->trend->total('collected')) }}</span></span>
                @php $lastPoint = collect($collections->trend->points)->last(); @endphp
                @if ($lastPoint)
                    <span>Outstanding at period end: <span class="font-medium text-(--content) tabular-nums">{{ ReportFormat::currency($lastPoint['values']['outstanding'] ?? 0) }}</span></span>
                @endif
            </div>
        </x-reports.section>

        <x-reports.section title="Collection efficiency" subtitle="Running collected ÷ receivable (%)"
            :error="$collections->efficiencyTrend->error" :empty="$collections->efficiencyTrend->isEmpty()"
            empty-text="Nothing to chart yet.">
            <x-reports.bar-chart :series="$collections->efficiencyTrend" metric="efficiency" format="number" />
            <p class="mt-3 border-t border-(--border) pt-3 text-xs text-(--content-muted)">
                Current efficiency:
                <span class="font-medium text-(--content) tabular-nums">{{ ReportFormat::percent($collections->kpi('efficiency')?->value) }}</span>
            </p>
        </x-reports.section>
    </div>

    {{-- Ageing + critical overdue --}}
    <div class="grid gap-6 lg:grid-cols-2">
        <x-reports.section title="Ageing of overdue receivables" subtitle="M8 buckets · outstanding amount"
            :error="$collections->error('Ageing')" :empty="$ageTotal === 0"
            empty-text="No overdue receivables.">
            <x-reports.distribution :items="$ageItems" :total="$ageTotal" />
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr><th class="py-2 pr-4">Bucket</th><th class="py-2 pr-4 text-right">Customers</th><th class="py-2 pr-4 text-right">Installments</th><th class="py-2 pr-4 text-right">Outstanding</th></tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($collections->ageing as $row)
                            <tr @class(['bg-(--surface-muted)/40' => $collections->extras->bucket?->value === $row['bucket']])>
                                <td class="py-2 pr-4">{{ $row['label'] }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['customers']) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['installments']) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($row['outstanding']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-reports.section>

        <x-reports.section title="Critical overdue" subtitle="91–180 and 180+ days — act now"
            :error="$collections->error('Ageing')" :empty="(float) collect($collections->criticalAgeing())->sum('outstanding') === 0.0"
            empty-text="Nothing in the critical bands.">
            <div class="space-y-3">
                @foreach ($collections->criticalAgeing() as $row)
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3">
                        <div>
                            <p class="font-semibold text-red-800">{{ $row['label'] }}</p>
                            <p class="text-xs text-red-700">{{ ReportFormat::number($row['customers']) }} customers · {{ ReportFormat::number($row['installments']) }} installments</p>
                        </div>
                        <div class="text-right">
                            <p class="text-lg font-bold tabular-nums text-red-800">{{ ReportFormat::currency($row['outstanding']) }}</p>
                            <a href="{{ route('reports.collections', $filters->toQueryString() + ['ageing_bucket' => $row['bucket'], 'recovery_sort' => 'days_overdue']) }}#recovery"
                               class="text-xs font-medium text-red-700 hover:underline">View customers →</a>
                        </div>
                    </div>
                @endforeach
                @can('collections.view')
                    <a href="{{ route('collections.queue') }}" wire:navigate class="inline-block text-sm font-medium text-(--brand-primary) hover:underline">Open the collections queue →</a>
                @endcan
            </div>
        </x-reports.section>
    </div>

    {{-- Project collection --}}
    <x-reports.section title="Project collection" :error="$collections->error('Project collection')" :empty="$collections->projectCollection === []">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-(--border) text-sm">
                <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                    <tr>
                        <th class="py-2 pr-4">Project</th>
                        <th class="py-2 pr-4 text-right">Receivable</th>
                        <th class="py-2 pr-4 text-right">Collected</th>
                        <th class="py-2 pr-4 text-right">Outstanding</th>
                        <th class="py-2 pr-4 text-right">Overdue</th>
                        <th class="py-2 pr-4 text-right">Collection %</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-(--border)">
                    @foreach ($collections->projectCollection as $row)
                        <tr class="hover:bg-(--surface-muted)/50">
                            <td class="py-2 pr-4 font-medium">
                                @can('projects.view')
                                    <a href="{{ route('projects.show', $row['project_id']) }}" wire:navigate class="text-(--brand-primary) hover:underline">{{ $row['project'] }}</a>
                                @else {{ $row['project'] }} @endcan
                            </td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($row['receivable']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($row['collected']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($row['outstanding']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums text-red-600">{{ ReportFormat::currency($row['overdue']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::percent($row['efficiency']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-reports.section>

    {{-- Block collection --}}
    <x-reports.section title="Block collection" :error="$collections->error('Block collection')" :empty="$collections->blockCollection === []">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-(--border) text-sm">
                <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                    <tr>
                        <th class="py-2 pr-4">Block</th>
                        <th class="py-2 pr-4 text-right">Receivable</th>
                        <th class="py-2 pr-4 text-right">Collected</th>
                        <th class="py-2 pr-4 text-right">Outstanding</th>
                        <th class="py-2 pr-4 text-right">Overdue</th>
                        <th class="py-2 pr-4 text-right">Collection %</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-(--border)">
                    @foreach ($collections->blockCollection as $row)
                        <tr class="hover:bg-(--surface-muted)/50">
                            <td class="py-2 pr-4 font-medium">
                                @can('plots.view')
                                    <a href="{{ route('plots.index', ['project' => $row['project_id'], 'block' => $row['block_id']]) }}" wire:navigate class="text-(--brand-primary) hover:underline">{{ $row['block'] }}</a>
                                @else {{ $row['block'] }} @endcan
                                <span class="text-xs text-(--content-muted)">· {{ $row['project'] }}</span>
                            </td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($row['receivable']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($row['collected']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($row['outstanding']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums text-red-600">{{ ReportFormat::currency($row['overdue']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::percent($row['efficiency']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-reports.section>

    {{-- Salesperson collection --}}
    <x-reports.section title="Salesperson collection"
        :subtitle="$collections->salespeopleScoped ? 'Your bookings only (you can only see your own).' : 'By booking attribution (created_by)'"
        :error="$collections->error('Salesperson collection')" :empty="$collections->salespersonCollection === []">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-(--border) text-sm">
                <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                    <tr>
                        <th class="py-2 pr-4">Salesperson</th>
                        <th class="py-2 pr-4 text-right">Customers</th>
                        <th class="py-2 pr-4 text-right">Receivable</th>
                        <th class="py-2 pr-4 text-right">Collected</th>
                        <th class="py-2 pr-4 text-right">Outstanding</th>
                        <th class="py-2 pr-4 text-right">Overdue</th>
                        <th class="py-2 pr-4 text-right">Collection %</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-(--border)">
                    @foreach ($collections->salespersonCollection as $row)
                        <tr class="hover:bg-(--surface-muted)/50">
                            <td class="py-2 pr-4 font-medium">{{ $row['name'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['customers']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($row['receivable']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($row['collected']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($row['outstanding']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums text-red-600">{{ ReportFormat::currency($row['overdue']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::percent($row['efficiency']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-reports.section>

    {{-- Customer recovery report --}}
    <span id="recovery"></span>
    <x-reports.section title="Customer recovery report"
        :subtitle="$collections->recovery->total().' overdue installment(s)'.($collections->extras->bucket ? ' · '.$collections->extras->bucket->label() : '')"
        :error="$collections->error('Customer recovery')" :empty="$collections->recovery->isEmpty()"
        empty-text="No overdue installments for the selected filters.">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-(--border) text-sm">
                <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                    <tr>
                        <th class="py-2 pr-4">Customer</th>
                        <th class="py-2 pr-4">Booking</th>
                        <th class="py-2 pr-4">Project</th>
                        <th class="py-2 pr-4">Installment</th>
                        <th class="py-2 pr-4">Due date</th>
                        <th class="py-2 pr-4 text-right">Due</th>
                        <th class="py-2 pr-4 text-right">Paid</th>
                        <th class="py-2 pr-4 text-right">
                            <a href="{{ $recoverySortLink('outstanding') }}#recovery" class="hover:text-(--content) {{ $collections->recoverySort === 'outstanding' ? 'text-(--brand-primary)' : '' }}">Outstanding{{ $sortCaret('outstanding') }}</a>
                        </th>
                        <th class="py-2 pr-4 text-right">
                            <a href="{{ $recoverySortLink('days_overdue') }}#recovery" class="hover:text-(--content) {{ $collections->recoverySort === 'days_overdue' ? 'text-(--brand-primary)' : '' }}">Days overdue{{ $sortCaret('days_overdue') }}</a>
                        </th>
                        <th class="py-2 pr-4">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-(--border)">
                    @foreach ($collections->recovery as $row)
                        @php $bucket = AgingBucket::fromDaysOverdue((int) $row->days_overdue); @endphp
                        <tr class="hover:bg-(--surface-muted)/50">
                            <td class="py-2 pr-4 font-medium">
                                @if ($row->buyer_id)
                                    @can('buyers.view')
                                        <a href="{{ route('buyers.show', $row->buyer_id) }}" wire:navigate class="text-(--brand-primary) hover:underline">{{ $buyerName($row) }}</a>
                                    @else {{ $buyerName($row) }} @endcan
                                @else {{ $buyerName($row) }} @endif
                            </td>
                            <td class="py-2 pr-4">
                                @can('bookings.view')
                                    <a href="{{ route('bookings.show', $row->booking_id) }}" wire:navigate class="text-(--brand-primary) hover:underline">{{ $row->booking_number }}</a>
                                @else {{ $row->booking_number }} @endcan
                            </td>
                            <td class="py-2 pr-4">{{ $row->project }}</td>
                            <td class="py-2 pr-4">
                                @can('payments.view')
                                    <a href="{{ route('payments.booking', $row->booking_id) }}" wire:navigate class="text-(--brand-primary) hover:underline">#{{ $row->installment_number }}{{ $row->installment_name ? ' · '.$row->installment_name : '' }}</a>
                                @else #{{ $row->installment_number }} @endcan
                            </td>
                            <td class="py-2 pr-4 tabular-nums">{{ \Illuminate\Support\Carbon::parse($row->due_date)->format('d M Y') }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currencyFull($row->due_amount) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currencyFull($row->paid) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums font-medium">{{ ReportFormat::currencyFull($row->outstanding) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row->days_overdue) }}</td>
                            <td class="py-2 pr-4">
                                <x-ui.badge :variant="$bucket?->color() ?? 'muted'">{{ $bucket?->label() ?? 'Overdue' }}</x-ui.badge>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($collections->recovery->hasPages())
            <div class="mt-3">{{ $collections->recovery->onEachSide(1)->links() }}</div>
        @endif
    </x-reports.section>

    {{-- Top outstanding + top overdue --}}
    <div class="grid gap-6 lg:grid-cols-2">
        <x-reports.section title="Top outstanding customers" subtitle="By total outstanding (not necessarily overdue)"
            :error="$collections->error('Top customers')" :empty="$collections->topCustomers['outstanding'] === []">
            <ul class="divide-y divide-(--border)">
                @foreach ($collections->topCustomers['outstanding'] as $c)
                    <li class="flex items-center justify-between gap-3 py-2 text-sm">
                        <span>
                            @can('buyers.view')
                                <a href="{{ route('buyers.show', $c['buyer_id']) }}" wire:navigate class="font-medium text-(--brand-primary) hover:underline">{{ $c['name'] }}</a>
                            @else <span class="font-medium">{{ $c['name'] }}</span> @endcan
                            <span class="text-xs text-(--content-muted)">{{ $c['customer_code'] }}</span>
                        </span>
                        <span class="tabular-nums font-medium">{{ ReportFormat::currency($c['amount']) }}</span>
                    </li>
                @endforeach
            </ul>
        </x-reports.section>

        <x-reports.section title="Top overdue customers" subtitle="By overdue amount only"
            :error="$collections->error('Top customers')" :empty="$collections->topCustomers['overdue'] === []">
            <ul class="divide-y divide-(--border)">
                @foreach ($collections->topCustomers['overdue'] as $c)
                    <li class="flex items-center justify-between gap-3 py-2 text-sm">
                        <span>
                            @can('buyers.view')
                                <a href="{{ route('buyers.show', $c['buyer_id']) }}" wire:navigate class="font-medium text-(--brand-primary) hover:underline">{{ $c['name'] }}</a>
                            @else <span class="font-medium">{{ $c['name'] }}</span> @endcan
                            <span class="text-xs text-(--content-muted)">{{ $c['customer_code'] }}</span>
                        </span>
                        <span class="tabular-nums font-medium text-red-600">{{ ReportFormat::currency($c['amount']) }}</span>
                    </li>
                @endforeach
            </ul>
        </x-reports.section>
    </div>

    {{-- Payment method + cheque analytics --}}
    <div class="grid gap-6 lg:grid-cols-2">
        <x-reports.section title="Payment method mix" subtitle="SUCCESS payments in the selected window"
            :error="$collections->error('Payment methods')" :empty="$collections->paymentMethods === []"
            empty-text="No payments in this period.">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr><th class="py-2 pr-4">Method</th><th class="py-2 pr-4 text-right">Amount</th><th class="py-2 pr-4 text-right">Txns</th><th class="py-2 pr-4 text-right">Share</th></tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($collections->paymentMethods as $row)
                            <tr>
                                <td class="py-2 pr-4">{{ $row['method'] }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($row['amount']) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::number($row['transactions']) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::percent($row['percent']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-reports.section>

        <x-reports.section title="Cheque analytics" subtitle="M7 cheque status + M8 bounces"
            :error="$collections->error('Cheque analytics')" :empty="$collections->cheques['received'] === 0"
            empty-text="No cheque payments recorded.">
            @php $ch = $collections->cheques; @endphp
            <dl class="grid grid-cols-2 gap-4">
                <div><dt class="text-xs uppercase tracking-wider text-(--content-muted)">Received</dt><dd class="text-xl font-semibold tabular-nums">{{ ReportFormat::number($ch['received']) }}</dd></div>
                <div><dt class="text-xs uppercase tracking-wider text-(--content-muted)">Cleared</dt><dd class="text-xl font-semibold tabular-nums text-emerald-600">{{ ReportFormat::number($ch['cleared']) }}</dd></div>
                <div><dt class="text-xs uppercase tracking-wider text-(--content-muted)">Pending</dt><dd class="text-xl font-semibold tabular-nums text-amber-600">{{ ReportFormat::number($ch['pending']) }}</dd></div>
                <div><dt class="text-xs uppercase tracking-wider text-(--content-muted)">Bounced</dt><dd class="text-xl font-semibold tabular-nums text-red-600">{{ ReportFormat::number($ch['bounced']) }}</dd></div>
                <div><dt class="text-xs uppercase tracking-wider text-(--content-muted)">Bounced amount</dt><dd class="text-xl font-semibold tabular-nums text-red-600">{{ ReportFormat::currency($ch['bounced_amount']) }}</dd></div>
                <div><dt class="text-xs uppercase tracking-wider text-(--content-muted)">Bank charges</dt><dd class="text-xl font-semibold tabular-nums">{{ ReportFormat::currency($ch['bank_charges']) }}</dd></div>
            </dl>
            <p class="mt-3 border-t border-(--border) pt-3 text-xs text-(--content-muted)">
                Bounced cheques are reversed in M7 — their amount is <span class="font-medium">not</span> part of collected money.
            </p>
        </x-reports.section>
    </div>

    {{-- Monthly collection --}}
    <x-reports.section title="Monthly collection" subtitle="Receivable (by due date) vs collected (by payment date)"
        :error="$collections->error('Monthly collection')" :empty="$collections->monthly === []">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-(--border) text-sm">
                <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                    <tr>
                        <th class="py-2 pr-4">Month</th>
                        <th class="py-2 pr-4 text-right">Receivable</th>
                        <th class="py-2 pr-4 text-right">Collected</th>
                        <th class="py-2 pr-4 text-right">Outstanding</th>
                        <th class="py-2 pr-4 text-right">Efficiency</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-(--border)">
                    @foreach ($collections->monthly as $row)
                        <tr>
                            <td class="py-2 pr-4 font-medium">{{ $row['month'] }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($row['receivable']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($row['collected']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::currency($row['outstanding']) }}</td>
                            <td class="py-2 pr-4 text-right tabular-nums">{{ ReportFormat::percent($row['efficiency']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-reports.section>

    {{-- Expected collection + booking vs collection --}}
    <div class="grid gap-6 lg:grid-cols-2">
        <x-reports.section title="Expected collection" subtitle="Unpaid dues coming up (whole book)"
            :error="$collections->error('Expected collection')">
            <dl class="grid grid-cols-2 gap-4">
                <div class="rounded-lg border border-(--border) p-4">
                    <dt class="text-xs uppercase tracking-wider text-(--content-muted)">Next 7 days</dt>
                    <dd class="mt-1 text-2xl font-semibold tabular-nums">{{ ReportFormat::currency($collections->expected['next7']) }}</dd>
                </div>
                <div class="rounded-lg border border-(--border) p-4">
                    <dt class="text-xs uppercase tracking-wider text-(--content-muted)">Next 30 days</dt>
                    <dd class="mt-1 text-2xl font-semibold tabular-nums">{{ ReportFormat::currency($collections->expected['next30']) }}</dd>
                </div>
            </dl>
            <p class="mt-3 text-xs text-(--content-muted)">Outstanding portion of installments due within the window. Not a forecast.</p>
        </x-reports.section>

        <x-reports.section title="Booking value vs collection" subtitle="Booking value is not cash collected"
            :error="$collections->error('Booking vs collection')">
            @php $r = $collections->reconciliation; @endphp
            <dl class="space-y-2 text-sm">
                <div class="flex items-baseline justify-between"><dt class="text-(--content-muted)">Booking value (confirmed)</dt><dd class="tabular-nums font-medium">{{ ReportFormat::currency($r['bookingValue']) }}</dd></div>
                <div class="flex items-baseline justify-between"><dt class="text-(--content-muted)">Receivable (demand raised)</dt><dd class="tabular-nums font-medium">{{ ReportFormat::currency($r['receivable']) }}</dd></div>
                <div class="flex items-baseline justify-between"><dt class="text-(--content-muted)">Cash collected (SUCCESS payments)</dt><dd class="tabular-nums font-medium text-emerald-600">{{ ReportFormat::currency($r['cashCollected']) }}</dd></div>
                <div class="flex items-baseline justify-between"><dt class="text-(--content-muted)">Outstanding (M8 walk)</dt><dd class="tabular-nums font-medium">{{ ReportFormat::currency($r['outstanding']) }}</dd></div>
                <div class="flex items-baseline justify-between border-t border-(--border) pt-2 text-xs text-(--content-muted)"><dt>Cash not yet allocated to an installment</dt><dd class="tabular-nums">{{ ReportFormat::currency($r['unallocated']) }}</dd></div>
            </dl>
        </x-reports.section>
    </div>

</x-layouts.app>
