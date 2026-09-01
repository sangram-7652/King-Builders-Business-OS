@props([
    'label' => null,
    'for' => null,
    'hint' => null,
    'error' => null,
    'required' => false,
])

<div {{ $attributes->merge(['class' => 'space-y-1.5']) }}>
    @if ($label)
        <label @if ($for) for="{{ $for }}" @endif class="block text-sm font-medium text-(--content)">
            {{ $label }}
            @if ($required)<span class="text-red-500">*</span>@endif
        </label>
    @endif

    {{ $slot }}

    @if ($hint && ! $error)
        <p class="text-xs text-(--content-muted)">{{ $hint }}</p>
    @endif

    @if ($error)
        <p class="text-xs font-medium text-red-600">{{ $error }}</p>
    @endif
</div>
