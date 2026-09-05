@props([
    'label' => null,
    'name' => null,
    'value' => '',
    'placeholder' => 'All',
    'options' => [],          // ['value' => 'Label']
    'disabled' => false,
])

@php
    $error = $name ? $errors->first($name) : null;
    $control = 'block w-full rounded-lg border bg-(--surface) px-3 py-2 text-sm text-(--content) focus-brand disabled:opacity-50 '
        . ($error ? 'border-red-400' : 'border-(--border)');
@endphp

<x-ui.field :label="$label" :for="$name" :error="$error">
    <select
        @if ($name) id="{{ $name }}" name="{{ $name }}" @endif
        @disabled($disabled)
        {{ $attributes->merge(['class' => $control]) }}
    >
        @if ($placeholder !== null)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $optValue => $optLabel)
            <option value="{{ $optValue }}" @selected((string) $optValue === (string) $value)>{{ $optLabel }}</option>
        @endforeach
    </select>
</x-ui.field>
