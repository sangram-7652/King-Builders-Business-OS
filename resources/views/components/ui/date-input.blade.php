@props([
    'label' => null,
    'name' => null,
    'hint' => null,
    'error' => null,
    'required' => false,
])

{{-- Thin wrapper over <x-ui.input> so date fields stay consistent and can gain
     a shared date picker later without touching call sites. --}}
<x-ui.input
    type="date"
    :label="$label"
    :name="$name"
    :hint="$hint"
    :error="$error"
    :required="$required"
    {{ $attributes }}
/>
