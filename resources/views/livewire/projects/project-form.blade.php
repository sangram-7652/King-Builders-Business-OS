@php
    $crumbs = [['label' => 'Projects', 'url' => route('projects.index')]];
    if ($this->editing) {
        $crumbs[] = ['label' => $project->name, 'url' => route('projects.show', $project)];
        $crumbs[] = ['label' => 'Edit'];
    } else {
        $crumbs[] = ['label' => 'New project'];
    }
@endphp

<div class="space-y-6">
    <x-ui.breadcrumb :items="$crumbs" />

    <x-ui.page-header
        :title="$this->editing ? 'Edit project' : 'New project'"
        :description="$this->editing ? $project->code : 'A new project starts in Planning. You can change its status from the project page.'" />

    <form wire:submit="save" class="space-y-6">
        <x-ui.card title="Basics">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input label="Name" wire:model="name" required :error="$errors->first('name')" />
                <x-ui.input label="Code" wire:model="code" required
                    hint="Unique, e.g. MK for Madhav Kunj. Letters, numbers and hyphens."
                    :error="$errors->first('code')" />
                <div class="sm:col-span-2">
                    <x-ui.textarea label="Description" wire:model="description" :error="$errors->first('description')" rows="3" />
                </div>
                <x-ui.date-input label="Launch date" wire:model="launch_date" :error="$errors->first('launch_date')" hint="Optional" />
            </div>
        </x-ui.card>

        <x-ui.card title="Location" subtitle="States and cities come from Master Data. Pick a state first.">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.select label="State" wire:model.live="state_id" placeholder="Select a state" required
                    :options="$states->toArray()" :error="$errors->first('state_id')" />
                <x-ui.select label="City" wire:model="city_id" placeholder="Select a city"
                    :options="$this->cities->toArray()" :disabled="$state_id === ''"
                    :error="$errors->first('city_id')"
                    :hint="$state_id === '' ? 'Choose a state to load its cities.' : 'Optional'" />
                <div class="sm:col-span-2">
                    <x-ui.input label="Address" wire:model="address" :error="$errors->first('address')" />
                </div>
                <x-ui.input label="Pincode" wire:model="pincode" :error="$errors->first('pincode')" />
                <div class="grid grid-cols-2 gap-3">
                    <x-ui.input label="Latitude" wire:model="latitude" :error="$errors->first('latitude')" hint="Optional" />
                    <x-ui.input label="Longitude" wire:model="longitude" :error="$errors->first('longitude')" hint="Optional" />
                </div>
            </div>
        </x-ui.card>

        <x-ui.card title="Primary contact">
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input label="Contact name" wire:model="contact_name" :error="$errors->first('contact_name')" />
                <x-ui.input label="Contact phone" wire:model="contact_phone" :error="$errors->first('contact_phone')" />
                <x-ui.input type="email" label="Contact email" wire:model="contact_email" :error="$errors->first('contact_email')" />
            </div>
        </x-ui.card>

        <x-ui.card title="Imagery" subtitle="Paths only for now — an uploader arrives in a later milestone.">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input label="Logo path / URL" wire:model="logo_path" :error="$errors->first('logo_path')" />
                <x-ui.input label="Cover image path / URL" wire:model="cover_image_path" :error="$errors->first('cover_image_path')" />
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary"
                :href="$this->editing ? route('projects.show', $project) : route('projects.index')"
                wire:navigate>Cancel</x-ui.button>
            <x-ui.button type="submit" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">{{ $this->editing ? 'Save changes' : 'Create project' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
            </x-ui.button>
        </div>
    </form>
</div>
