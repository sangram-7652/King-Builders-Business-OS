@props([
    'title' => 'Nothing here yet',
    'description' => null,
    'icon' => 'inbox',
])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center rounded-lg border border-dashed border-(--border) px-6 py-10 text-center']) }}>
    <span class="flex size-11 items-center justify-center rounded-full bg-(--surface-muted) text-(--content-muted)">
        <x-app.icon :name="$icon" class="size-5" />
    </span>
    <p class="mt-3 text-sm font-medium text-(--content)">{{ $title }}</p>
    @if ($description)
        <p class="mt-1 max-w-sm text-xs text-(--content-muted)">{{ $description }}</p>
    @endif
    @isset($action)
        <div class="mt-4">{{ $action }}</div>
    @endisset
</div>
