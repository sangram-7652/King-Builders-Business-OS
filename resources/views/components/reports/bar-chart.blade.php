@props([
    'series',            // App\Support\Reports\ChartSeries
    'metric',            // key within each point's values
    'format' => 'number', // number | currency
])

@php
    use App\Support\Reports\ReportFormat;

    /** @var \App\Support\Reports\ChartSeries $series */
    $max = $series->max($metric);
    $fmt = fn ($v) => $format === 'currency' ? ReportFormat::currency($v) : ReportFormat::number($v);
@endphp

<div class="overflow-x-auto">
    <div class="flex min-w-full items-end gap-1.5" style="min-width: {{ max(count($series->points) * 28, 240) }}px; height: 12rem;">
        @foreach ($series->points as $point)
            @php
                $value = $point['values'][$metric] ?? 0;
                $pct = $max > 0 ? max(round($value / $max * 100, 2), $value > 0 ? 2 : 0) : 0;
            @endphp
            <div class="group flex flex-1 flex-col items-center justify-end" style="height: 100%">
                <div class="relative flex w-full flex-1 items-end">
                    <div class="w-full rounded-t bg-(--brand-primary)/80 transition-all group-hover:bg-(--brand-primary)"
                         style="height: {{ $pct }}%"
                         title="{{ $point['label'] }} · {{ $fmt($value) }}"></div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-2 flex gap-1.5" style="min-width: {{ max(count($series->points) * 28, 240) }}px;">
        @foreach ($series->points as $i => $point)
            <div class="flex-1 truncate text-center text-[10px] text-(--content-muted)"
                 @if (count($series->points) > 12 && $i % 2 !== 0) style="visibility:hidden" @endif>
                {{ $point['label'] }}
            </div>
        @endforeach
    </div>
</div>
