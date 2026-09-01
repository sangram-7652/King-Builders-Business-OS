@props([
    'name' => null,          // when set, open via $dispatch('open-modal', '<name>')
    'title' => null,
    'maxWidth' => 'lg',      // sm | md | lg | xl | 2xl
])

@php
    $widths = [
        'sm' => 'max-w-sm', 'md' => 'max-w-md', 'lg' => 'max-w-lg',
        'xl' => 'max-w-xl', '2xl' => 'max-w-2xl',
    ][$maxWidth] ?? 'max-w-lg';
@endphp

<div
    x-data="{ open: false }"
    @if ($name)
        @open-modal.window="$event.detail === '{{ $name }}' && (open = true)"
        @close-modal.window="$event.detail === '{{ $name }}' && (open = false)"
    @endif
    x-on:keydown.escape.window="open = false"
    x-show="open"
    style="display:none"
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    role="dialog"
    aria-modal="true"
>
    <div x-show="open" x-transition.opacity @click="open = false" class="absolute inset-0 bg-slate-900/50"></div>

    <div x-show="open"
         x-transition
         class="relative w-full {{ $widths }} rounded-xl border border-(--border) bg-(--surface) shadow-xl">
        <div class="flex items-start justify-between border-b border-(--border) px-5 py-4">
            <h3 class="text-sm font-semibold text-(--content)">{{ $title }}</h3>
            <button type="button" @click="open = false" class="rounded-lg p-1 text-(--content-muted) hover:bg-(--surface-muted)" aria-label="Close">
                <x-app.icon name="x" class="size-4" />
            </button>
        </div>

        <div class="px-5 py-4">{{ $slot }}</div>

        @isset($footer)
            <div class="flex justify-end gap-2 border-t border-(--border) px-5 py-3">{{ $footer }}</div>
        @endisset
    </div>
</div>
