@php
    /** @var \App\Masters\Field $field */
    $key = $field->key;
    $model = "form.$key";
    $error = $errors->first($key);
@endphp

@switch($field->type)
    @case('textarea')
        <x-ui.textarea
            :label="$field->label"
            wire:model="{{ $model }}"
            :required="$field->isRequired()"
            :hint="$field->getHelp()"
            :error="$error"
            :placeholder="$field->getPlaceholder()" />
        @break

    @case('select')
        <x-ui.select
            :label="$field->label"
            wire:model="{{ $model }}"
            :required="$field->isRequired()"
            :hint="$field->getHelp()"
            :error="$error"
            placeholder="Select…"
            :options="$field->resolveOptions()" />
        @break

    @case('toggle')
        <div class="space-y-1.5">
            <label class="flex items-center gap-2 text-sm font-medium text-(--content)">
                <input type="checkbox" wire:model="{{ $model }}" class="rounded border-(--border)">
                {{ $field->label }}
            </label>
            @if ($field->getHelp())
                <p class="text-xs text-(--content-muted)">{{ $field->getHelp() }}</p>
            @endif
            @if ($error)<p class="text-xs font-medium text-red-600">{{ $error }}</p>@endif
        </div>
        @break

    @case('date')
        <x-ui.date-input
            :label="$field->label"
            wire:model="{{ $model }}"
            :required="$field->isRequired()"
            :hint="$field->getHelp()"
            :error="$error" />
        @break

    @case('number')
        <x-ui.input
            type="number"
            :label="$field->label"
            wire:model="{{ $model }}"
            step="{{ $field->getStep() ?? 'any' }}"
            :required="$field->isRequired()"
            :hint="$field->getHelp()"
            :error="$error"
            :placeholder="$field->getPlaceholder()" />
        @break

    @default
        <x-ui.input
            :label="$field->label"
            wire:model="{{ $model }}"
            :required="$field->isRequired()"
            :hint="$field->getHelp()"
            :error="$error"
            :placeholder="$field->getPlaceholder()" />
@endswitch
