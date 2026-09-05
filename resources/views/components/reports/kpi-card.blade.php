@props(['kpi', 'icon' => null])

@php
    use App\Support\Reports\ReportFormat;

    /** @var \App\Support\Reports\Kpi $kpi */
    $display = match ($kpi->format) {
        'currency' => ReportFormat::currency($kpi->value),
        'percent' => ReportFormat::percent($kpi->value),
        default => ReportFormat::number($kpi->value),
    };

    $delta = $kpi->delta();
    $direction = $kpi->direction();
    $deltaClasses = match ($direction) {
        'up' => 'text-emerald-600',
        'down' => 'text-red-600',
        default => 'text-(--content-muted)',
    };
@endphp

<div class="rounded-xl border border-(--border) bg-(--surface) p-4 shadow-sm">
    <div class="flex items-start justify-between gap-2">
        <p class="text-xs font-medium uppercase tracking-wider text-(--content-muted)">{{ $kpi->label }}</p>
        @if ($icon)
            <span class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-(--brand-primary)/10 text-(--brand-primary)">
                <x-app.icon :name="$icon" class="size-4" />
            </span>
        @endif
    </div>

    @if ($kpi->failed())
        <p class="mt-2 text-lg font-semibold text-red-600" title="{{ $kpi->error }}">Unavailable</p>
        <p class="mt-1 text-xs text-(--content-muted)">Data could not be loaded</p>
    @else
        <p class="mt-2 text-2xl font-semibold tracking-tight text-(--content) tabular-nums">{{ $display }}</p>

        <div class="mt-1 flex items-center gap-2 text-xs">
            @if ($delta !== null)
                <span class="{{ $deltaClasses }} font-medium">
                    @if ($direction === 'up') ▲ @elseif ($direction === 'down') ▼ @else ▬ @endif
                    {{ $delta > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($delta, 1), '0'), '.') }}{{ $kpi->deltaUnit() }}
                </span>
                <span class="text-(--content-muted)">vs prev.</span>
            @elseif ($kpi->hint)
                <span class="text-(--content-muted)">{{ $kpi->hint }}</span>
            @endif
        </div>
    @endif
</div>
