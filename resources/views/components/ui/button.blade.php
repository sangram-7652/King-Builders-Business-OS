@props([
    'variant' => 'primary',   // primary | secondary | ghost | danger
    'size' => 'md',           // sm | md | lg
    'type' => 'button',
    'href' => null,
    'loading' => false,
])

@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition focus-brand disabled:cursor-not-allowed disabled:opacity-50';

    $variants = [
        'primary'   => 'bg-(--brand-primary) text-(--brand-primary-fg) hover:bg-(--brand-primary-hover)',
        'secondary' => 'border border-(--border) bg-(--surface) text-(--content) hover:bg-(--surface-muted)',
        'ghost'     => 'text-(--content-muted) hover:bg-(--surface-muted) hover:text-(--content)',
        'danger'    => 'bg-red-600 text-white hover:bg-red-700',
    ];

    $sizes = [
        'sm' => 'px-2.5 py-1.5 text-xs',
        'md' => 'px-3.5 py-2 text-sm',
        'lg' => 'px-5 py-2.5 text-base',
    ];

    $classes = implode(' ', [$base, $variants[$variant] ?? $variants['primary'], $sizes[$size] ?? $sizes['md']]);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" @disabled($loading) {{ $attributes->merge(['class' => $classes]) }}>
        @if ($loading)
            <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
            </svg>
        @endif
        {{ $slot }}
    </button>
@endif
