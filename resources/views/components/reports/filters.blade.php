@props([
    'filters',          // App\Support\Reports\ReportFilterData
    'options',          // App\Support\Reports\FilterOptions
    'action',           // route() the form submits to (also the Reset target)
    'canExport' => false,
    'report' => null,   // report key — enables the export menu (sales|inventory|collections|mis)
    'only' => null,     // list<string> of control keys to render; null = the global set
    'extras' => null,   // App\Support\Reports\InventoryFilters (for price/size ranges)
])

@php
    $v = $filters->toFormValues();
    $ev = array_merge(
        ['price_min' => '', 'price_max' => '', 'size_min' => '', 'size_max' => '', 'ageing_bucket' => '', 'payment_method' => ''],
        $extras?->toFormValues() ?? [],
    );
    $customPreset = \App\Enums\DatePreset::Custom->value;

    // Which controls to show. null => the original global set (backward compatible).
    $default = ['period', 'project_id', 'block_id', 'salesperson_id', 'booking_status', 'payment_status', 'plot_status', 'lead_source'];
    $show = fn (string $k) => $only === null ? in_array($k, $default, true) : in_array($k, $only, true);

    $rangeClass = 'block w-full rounded-lg border border-(--border) bg-(--surface) px-3 py-2 text-sm text-(--content) focus-brand';
@endphp

<x-ui.card title="Filters" subtitle="Applied to every figure on this page. Filters persist in the URL.">
    <x-slot:actions>
        <a href="{{ $action }}"
           class="inline-flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-xs font-medium text-(--content-muted) transition hover:bg-(--surface-muted) hover:text-(--content)">
            <x-app.icon name="x" class="size-3.5" />
            Reset
        </a>
    </x-slot:actions>

    <form method="GET" action="{{ $action }}"
          x-data="{ preset: @js($v['preset']), custom: '{{ $customPreset }}' }"
          class="space-y-4">

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @if ($show('period'))
                <x-reports.select label="Period" name="preset" :value="$v['preset']" :placeholder="null"
                    :options="$options->datePresets" x-model="preset" />

                <x-ui.field label="From" for="from">
                    <input type="date" id="from" name="from" value="{{ $v['from'] }}"
                           x-bind:disabled="preset !== custom"
                           class="{{ $rangeClass }} disabled:opacity-50 {{ $errors->first('from') ? 'border-red-400' : '' }}" />
                    @error('from') <p class="text-xs font-medium text-red-600">{{ $message }}</p> @enderror
                </x-ui.field>

                <x-ui.field label="To" for="to">
                    <input type="date" id="to" name="to" value="{{ $v['to'] }}"
                           x-bind:disabled="preset !== custom"
                           class="{{ $rangeClass }} disabled:opacity-50 {{ $errors->first('to') ? 'border-red-400' : '' }}" />
                    @error('to') <p class="text-xs font-medium text-red-600">{{ $message }}</p> @enderror
                </x-ui.field>
            @endif

            @if ($show('project_id'))
                <x-reports.select label="Project" name="project_id" :value="$v['project_id']" :options="$options->projects"
                    onchange="this.form.block_id.value=''; this.form.submit()" />
            @endif

            @if ($show('block_id'))
                <x-reports.select label="Block" name="block_id" :value="$v['block_id']" :options="$options->blocks"
                    :disabled="$options->blocks === []"
                    :placeholder="$options->blocks === [] ? 'Select a project first' : 'All blocks'" />
            @endif

            @if ($show('salesperson_id'))
                <x-reports.select label="Salesperson" name="salesperson_id" :value="$v['salesperson_id']"
                    :options="$options->salespeople" placeholder="All salespeople" />
            @endif

            @if ($show('booking_status'))
                <x-reports.select label="Booking status" name="booking_status" :value="$v['booking_status']" :options="$options->bookingStatuses" />
            @endif

            @if ($show('payment_status'))
                <x-reports.select label="Payment status" name="payment_status" :value="$v['payment_status']" :options="$options->paymentStatuses" />
            @endif

            @if ($show('plot_status'))
                <x-reports.select label="Plot status" name="plot_status" :value="$v['plot_status']" :options="$options->plotStatuses" />
            @endif

            @if ($show('lead_source'))
                <x-reports.select label="Lead source" name="lead_source" :value="$v['lead_source']" :options="$options->leadSources" />
            @endif

            @if ($show('ageing_bucket'))
                <x-reports.select label="Ageing bucket" name="ageing_bucket" :value="$ev['ageing_bucket']"
                    :options="$options->ageingBuckets" placeholder="All buckets" />
            @endif

            @if ($show('payment_method'))
                <x-reports.select label="Payment method" name="payment_method" :value="$ev['payment_method']"
                    :options="$options->paymentModes" placeholder="All methods" />
            @endif

            @if ($show('size_range'))
                <x-ui.field label="Size min (area)" for="size_min">
                    <input type="number" step="any" min="0" id="size_min" name="size_min" value="{{ $ev['size_min'] }}"
                           class="{{ $rangeClass }} {{ $errors->first('size_min') ? 'border-red-400' : '' }}" />
                </x-ui.field>
                <x-ui.field label="Size max (area)" for="size_max">
                    <input type="number" step="any" min="0" id="size_max" name="size_max" value="{{ $ev['size_max'] }}"
                           class="{{ $rangeClass }} {{ $errors->first('size_max') ? 'border-red-400' : '' }}" />
                    @error('size_max') <p class="text-xs font-medium text-red-600">{{ $message }}</p> @enderror
                </x-ui.field>
            @endif

            @if ($show('price_range'))
                <x-ui.field label="Price min (₹)" for="price_min" hint="Applies to booked plots only">
                    <input type="number" step="any" min="0" id="price_min" name="price_min" value="{{ $ev['price_min'] }}"
                           class="{{ $rangeClass }} {{ $errors->first('price_min') ? 'border-red-400' : '' }}" />
                </x-ui.field>
                <x-ui.field label="Price max (₹)" for="price_max">
                    <input type="number" step="any" min="0" id="price_max" name="price_max" value="{{ $ev['price_max'] }}"
                           class="{{ $rangeClass }} {{ $errors->first('price_max') ? 'border-red-400' : '' }}" />
                    @error('price_max') <p class="text-xs font-medium text-red-600">{{ $message }}</p> @enderror
                </x-ui.field>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2 border-t border-(--border) pt-4">
            <x-ui.button type="submit" size="sm">Apply filters</x-ui.button>
            <x-ui.button :href="$action" variant="secondary" size="sm">Reset</x-ui.button>

            <span class="ml-auto text-xs text-(--content-muted)">
                Showing <span class="font-medium text-(--content)">{{ $filters->periodLabel() }}</span>
                ({{ $filters->from->format('d M Y') }} – {{ $filters->to->format('d M Y') }})
            </span>

        </div>

        @if ($canExport && $report !== null)
            <div class="border-t border-(--border) pt-4">
                <x-reports.export-menu :report="$report" :filters="$filters" :extras="$extras" />
            </div>
        @endif
    </form>
</x-ui.card>
