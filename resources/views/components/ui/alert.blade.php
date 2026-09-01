@props([
    'variant' => 'info',    // info | success | warning | danger
    'title' => null,
    'dismissible' => false,
])

@php
    $variants = [
        'info'    => 'border-sky-500/30 bg-sky-500/5 text-sky-800',
        'success' => 'border-emerald-500/30 bg-emerald-500/5 text-emerald-800',
        'warning' => 'border-amber-500/30 bg-amber-500/5 text-amber-800',
        'danger'  => 'border-red-500/30 bg-red-500/5 text-red-800',
    ];
    $classes = 'rounded-lg border px-4 py-3 text-sm ' . ($variants[$variant] ?? $variants['info']);
@endphp

<div x-data="{ show: true }" x-show="show" {{ $attributes->merge(['class' => $classes]) }} role="alert">
    <div class="flex items-start gap-3">
        <div class="flex-1">
            @if ($title)<p class="font-semibold">{{ $title }}</p>@endif
            <div @class(['mt-0.5' => $title])>{{ $slot }}</div>
        </div>
        @if ($dismissible)
            <button type="button" @click="show = false" class="shrink-0 opacity-70 hover:opacity-100" aria-label="Dismiss">
                <x-app.icon name="x" class="size-4" />
            </button>
        @endif
    </div>
</div>
