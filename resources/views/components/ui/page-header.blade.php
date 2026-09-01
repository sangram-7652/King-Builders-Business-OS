@props([
    'title' => '',
    'description' => null,
])

<div {{ $attributes->merge(['class' => 'flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between']) }}>
    <div class="min-w-0">
        <h1 class="truncate text-xl font-semibold tracking-tight text-(--content)">{{ $title }}</h1>
        @if ($description)
            <p class="mt-1 text-sm text-(--content-muted)">{{ $description }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
