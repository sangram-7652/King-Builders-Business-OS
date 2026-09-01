@props([
    'label' => '',
    'value' => '—',
    'delta' => null,           // e.g. "+12%" or "-3"
    'trend' => null,           // up | down | neutral
    'icon' => null,
])

@php
    $trendClasses = [
        'up' => 'text-emerald-600',
        'down' => 'text-red-600',
        'neutral' => 'text-(--content-muted)',
    ][$trend] ?? 'text-(--content-muted)';
@endphp

<div {{ $attributes->merge(['class' => 'rounded-xl border border-(--border) bg-(--surface) p-5 shadow-sm']) }}>
    <div class="flex items-center justify-between">
        <p class="text-xs font-medium uppercase tracking-wider text-(--content-muted)">{{ $label }}</p>
        @if ($icon)
            <span class="flex size-8 items-center justify-center rounded-lg bg-(--brand-primary)/10 text-(--brand-primary)">
                <x-app.icon :name="$icon" class="size-4" />
            </span>
        @endif
    </div>
    <p class="mt-2 text-2xl font-semibold tracking-tight text-(--content)">{{ $value }}</p>
    @if ($delta)
        <p class="mt-1 text-xs font-medium {{ $trendClasses }}">{{ $delta }}</p>
    @endif
</div>
