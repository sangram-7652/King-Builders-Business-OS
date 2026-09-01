@props([
    'variant' => 'muted',   // brand | muted | success | warning | danger | info
    'size' => 'md',         // sm | md
])

@php
    $variants = [
        'brand'   => 'bg-(--brand-primary)/10 text-(--brand-primary) ring-(--brand-primary)/20',
        'muted'   => 'bg-slate-500/10 text-slate-500 ring-slate-500/20',
        'success' => 'bg-emerald-500/10 text-emerald-600 ring-emerald-500/20',
        'warning' => 'bg-amber-500/10 text-amber-600 ring-amber-500/20',
        'danger'  => 'bg-red-500/10 text-red-600 ring-red-500/20',
        'info'    => 'bg-sky-500/10 text-sky-600 ring-sky-500/20',
    ];

    $sizes = [
        'sm' => 'px-1.5 py-0.5 text-[10px]',
        'md' => 'px-2 py-0.5 text-xs',
    ];

    $classes = 'inline-flex items-center gap-1 rounded-full font-medium ring-1 ring-inset '
        . ($variants[$variant] ?? $variants['muted']) . ' ' . ($sizes[$size] ?? $sizes['md']);
@endphp

<span {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</span>
