@props([
    'name' => null,          // open via $dispatch('open-drawer', '<name>')
    'title' => null,
    'side' => 'right',       // right | left
    'width' => 'w-full sm:max-w-md',
])

@php $isRight = $side === 'right'; @endphp

<div
    x-data="{ open: false }"
    @if ($name)
        @open-drawer.window="$event.detail === '{{ $name }}' && (open = true)"
        @close-drawer.window="$event.detail === '{{ $name }}' && (open = false)"
    @endif
    x-on:keydown.escape.window="open = false"
    x-show="open"
    style="display:none"
    class="fixed inset-0 z-50"
    role="dialog"
    aria-modal="true"
>
    <div x-show="open" x-transition.opacity @click="open = false" class="absolute inset-0 bg-slate-900/50"></div>

    <div x-show="open"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="{{ $isRight ? 'translate-x-full' : '-translate-x-full' }}"
         x-transition:enter-end="translate-x-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="translate-x-0"
         x-transition:leave-end="{{ $isRight ? 'translate-x-full' : '-translate-x-full' }}"
         @class([
             'absolute inset-y-0 flex flex-col border-(--border) bg-(--surface) shadow-xl',
             $width,
             'right-0 border-l' => $isRight,
             'left-0 border-r' => ! $isRight,
         ])>
        <div class="flex items-center justify-between border-b border-(--border) px-5 py-4">
            <h3 class="text-sm font-semibold text-(--content)">{{ $title }}</h3>
            <button type="button" @click="open = false" class="rounded-lg p-1 text-(--content-muted) hover:bg-(--surface-muted)" aria-label="Close">
                <x-app.icon name="x" class="size-4" />
            </button>
        </div>

        <div class="flex-1 overflow-y-auto px-5 py-4">{{ $slot }}</div>

        @isset($footer)
            <div class="flex justify-end gap-2 border-t border-(--border) px-5 py-3">{{ $footer }}</div>
        @endisset
    </div>
</div>
