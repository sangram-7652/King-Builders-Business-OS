@props([
    'items',        // list<array{label:string, count:int, color?:string}>
    'total' => null,
])

@php
    $total ??= collect($items)->sum('count');
    $max = collect($items)->max('count') ?: 1;
    $barColor = [
        'success' => 'bg-emerald-500',
        'info' => 'bg-sky-500',
        'warning' => 'bg-amber-500',
        'danger' => 'bg-red-500',
        'brand' => 'bg-(--brand-primary)',
        'muted' => 'bg-slate-400',
    ];
@endphp

<div class="space-y-2">
    @foreach ($items as $item)
        @php $pct = $max > 0 ? round(($item['count'] / $max) * 100) : 0; @endphp
        <div class="flex items-center gap-3 text-sm">
            <span class="w-32 shrink-0 truncate text-(--content-muted)">{{ $item['label'] }}</span>
            <div class="h-3 flex-1 overflow-hidden rounded bg-(--surface-muted)">
                <div class="h-full rounded {{ $barColor[$item['color'] ?? 'brand'] ?? 'bg-(--brand-primary)' }}"
                     style="width: {{ max($pct, $item['count'] > 0 ? 3 : 0) }}%"></div>
            </div>
            <span class="w-16 shrink-0 text-right font-medium tabular-nums">
                {{ number_format($item['count']) }}
                @if ($total > 0)
                    <span class="text-xs font-normal text-(--content-muted)">{{ round($item['count'] / $total * 100) }}%</span>
                @endif
            </span>
        </div>
    @endforeach
</div>
