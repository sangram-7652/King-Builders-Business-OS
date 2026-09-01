@props([
    'title' => null,
    'subtitle' => null,
    'padding' => true,
])

<div {{ $attributes->merge(['class' => 'rounded-xl border border-(--border) bg-(--surface) shadow-sm']) }}>
    @if ($title || $subtitle || isset($actions))
        <div class="flex items-start justify-between gap-4 border-b border-(--border) px-5 py-4">
            <div>
                @if ($title)<h3 class="text-sm font-semibold text-(--content)">{{ $title }}</h3>@endif
                @if ($subtitle)<p class="mt-0.5 text-xs text-(--content-muted)">{{ $subtitle }}</p>@endif
            </div>
            @isset($actions)
                <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    <div @class(['px-5 py-4' => $padding])>
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="border-t border-(--border) px-5 py-3 text-sm text-(--content-muted)">{{ $footer }}</div>
    @endisset
</div>
