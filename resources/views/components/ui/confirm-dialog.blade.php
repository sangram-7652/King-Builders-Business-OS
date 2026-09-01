@props([
    'name' => 'confirm',
    'title' => 'Are you sure?',
    'message' => 'This action cannot be undone.',
    'confirmLabel' => 'Confirm',
    'cancelLabel' => 'Cancel',
    'variant' => 'danger',   // danger | primary
])

{{--
    Generic confirmation dialog.

    Open:      $dispatch('open-modal', '{{ $name }}')
    On accept: dispatches a window event  '{{ $name }}:confirmed'  that the
               caller (Livewire component / Alpine) listens for.
--}}
<x-ui.modal :name="$name" :title="$title" max-width="sm">
    <p class="text-sm text-(--content-muted)">{{ $message }}</p>

    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="open = false">{{ $cancelLabel }}</x-ui.button>
        <x-ui.button
            :variant="$variant === 'danger' ? 'danger' : 'primary'"
            x-on:click="$dispatch('{{ $name }}:confirmed'); open = false"
        >{{ $confirmLabel }}</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
