@props([
    'title',
    'subtitle' => null,
    'error' => null,
    'empty' => false,
    'emptyText' => 'No data for the selected filters.',
])

<x-ui.card :title="$title" :subtitle="$subtitle" {{ $attributes }}>
    @isset($actions)
        <x-slot:actions>{{ $actions }}</x-slot:actions>
    @endisset

    @if ($error)
        <div class="flex items-center gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <x-app.icon name="bell" class="size-4 shrink-0" />
            <span>{{ $error }}</span>
        </div>
    @elseif ($empty)
        <x-ui.empty-state :title="$emptyText" icon="inbox" />
    @else
        {{ $slot }}
    @endif
</x-ui.card>
