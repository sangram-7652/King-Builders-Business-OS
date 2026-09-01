@props([
    'label' => null,
    'name' => null,
    'type' => 'text',
    'hint' => null,
    'error' => null,
    'required' => false,
])

@php
    $id = $attributes->get('id', $name);
    $error ??= $name ? $errors->first($name) : null;
    $control = 'block w-full rounded-lg border bg-(--surface) px-3 py-2 text-sm text-(--content) placeholder:text-(--content-muted)/60 focus-brand disabled:opacity-50 '
        . ($error ? 'border-red-400' : 'border-(--border)');
@endphp

<x-ui.field :label="$label" :for="$id" :hint="$hint" :error="$error" :required="$required">
    <input
        type="{{ $type }}"
        @if ($id) id="{{ $id }}" @endif
        @if ($name) name="{{ $name }}" @endif
        @required($required)
        {{ $attributes->merge(['class' => $control]) }}
    />
</x-ui.field>
