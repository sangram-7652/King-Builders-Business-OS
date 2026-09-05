@props(['item'])

@php
    /** @var array{key:string,label:string,count:int|null,url:?string,tone:string,error:?string} $item */
    $tone = [
        'danger' => 'border-red-200 bg-red-50 text-red-700',
        'warning' => 'border-amber-200 bg-amber-50 text-amber-800',
        'info' => 'border-sky-200 bg-sky-50 text-sky-700',
    ][$item['tone']] ?? 'border-(--border) bg-(--surface-muted) text-(--content)';

    $count = $item['error'] !== null
        ? '!'
        : ($item['count'] === null ? '—' : number_format($item['count']));

    $classes = "flex items-center justify-between gap-3 rounded-lg border px-3 py-2.5 text-sm transition {$tone}";
@endphp

@if ($item['url'])
    <a href="{{ $item['url'] }}" class="{{ $classes }} hover:brightness-95">
@else
    <div class="{{ $classes }}">
@endif
        <span class="font-medium">
            {{ $item['label'] }}
            @if ($item['error'])<span class="block text-xs font-normal opacity-80">Could not load</span>@endif
        </span>
        <span class="flex items-center gap-1.5">
            <span class="rounded-full bg-white/60 px-2 py-0.5 text-sm font-bold tabular-nums">{{ $count }}</span>
            @if ($item['url'])<x-app.icon name="chevron-down" class="size-4 -rotate-90" />@endif
        </span>
@if ($item['url'])
    </a>
@else
    </div>
@endif
