@props([
    'label' => null,
    'name' => null,
    'hint' => null,
    'error' => null,
    'required' => false,
    'placeholder' => null,
    'options' => [],        // ['value' => 'Label'] or list of scalars
])

@php
    $id = $attributes->get('id', $name);
    $error ??= $name ? $errors->first($name) : null;
    $control = 'block w-full rounded-lg border bg-(--surface) px-3 py-2 text-sm text-(--content) focus-brand disabled:opacity-50 '
        . ($error ? 'border-red-400' : 'border-(--border)');

    // A sequential list (['Active', 'Inactive']) uses the label as the value;
    // an associative map (['active' => 'Active'] or [5 => 'Haryana']) uses the key.
    $optionsIsList = is_array($options) && array_is_list($options);
@endphp

<x-ui.field :label="$label" :for="$id" :hint="$hint" :error="$error" :required="$required">
    <select
        @if ($id) id="{{ $id }}" @endif
        @if ($name) name="{{ $name }}" @endif
        @required($required)
        {{ $attributes->merge(['class' => $control]) }}
    >
        @if ($placeholder)
            <option value="">{{ $placeholder }}</option>
        @endif

        @if (! empty($options))
            @foreach ($options as $value => $text)
                <option value="{{ $optionsIsList ? $text : $value }}">{{ $text }}</option>
            @endforeach
        @else
            {{ $slot }}
        @endif
    </select>
</x-ui.field>
