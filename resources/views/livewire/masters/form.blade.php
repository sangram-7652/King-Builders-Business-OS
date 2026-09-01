<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Master Data', 'url' => route('masters.dashboard')],
        ['label' => $config->pluralLabel(), 'url' => route('masters.index', ['resource' => $config->slug()])],
        ['label' => $this->editing ? 'Edit' : 'New'],
    ]" />

    <x-ui.page-header
        :title="($this->editing ? 'Edit ' : 'New ').strtolower($config->singularLabel())"
        :description="$config->pluralLabel()" />

    <form wire:submit="save" class="space-y-6">
        <x-ui.card>
            <div class="grid gap-5 sm:grid-cols-2">
                @foreach ($config->fields() as $field)
                    <div @class(['sm:col-span-2' => in_array($field->type, ['textarea'], true)])>
                        @include('livewire.masters._field', ['field' => $field])
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary" :href="route('masters.index', ['resource' => $config->slug()])" wire:navigate>
                Cancel
            </x-ui.button>
            <x-ui.button type="submit">
                {{ $this->editing ? 'Save changes' : 'Create '.strtolower($config->singularLabel()) }}
            </x-ui.button>
        </div>
    </form>
</div>
