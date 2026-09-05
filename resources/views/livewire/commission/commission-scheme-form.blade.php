<div class="space-y-6">
    @php
        $crumbs = [['label' => 'Commission schemes', 'url' => route('commission-schemes.index')]];
        $crumbs[] = $this->editing
            ? ['label' => $scheme->name, 'url' => route('commission-schemes.show', $scheme)]
            : ['label' => 'New scheme'];
        if ($this->editing) $crumbs[] = ['label' => 'Edit'];
    @endphp
    <x-ui.breadcrumb :items="$crumbs" />

    <x-ui.page-header
        :title="$this->editing ? 'Edit scheme' : 'New commission scheme'"
        :description="$this->editing ? $scheme->code.' · v'.$scheme->version.' (draft)' : 'The scheme code is generated automatically. Add a rule and publish it to put it in force.'" />

    <form wire:submit="save" class="space-y-6">
        <x-ui.card title="Scheme">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input label="Name" wire:model="name" required :error="$errors->first('name')" />
                <x-ui.select label="Basis" wire:model="basis" :options="$bases" required :error="$errors->first('basis')"
                    hint="Which existing financial figure the commission is calculated from." />
                <div class="sm:col-span-2">
                    <x-ui.textarea label="Description" wire:model="description" rows="2" :error="$errors->first('description')" />
                </div>
                <x-ui.select label="Auto-match partner type" wire:model="partner_type" placeholder="— none —"
                    :options="$partnerTypes" :error="$errors->first('partner_type')"
                    hint="Applied to partners of this type when they have no scheme of their own." />
                <div class="flex items-center gap-2 pt-6">
                    <input type="checkbox" id="is_default" wire:model="is_default" class="rounded border-(--border)" />
                    <label for="is_default" class="text-sm">Use as the default fallback scheme</label>
                </div>
                <x-ui.date-input label="Effective from" wire:model="effective_from" :error="$errors->first('effective_from')" />
                <x-ui.date-input label="Effective to" wire:model="effective_to" :error="$errors->first('effective_to')" />
            </div>
        </x-ui.card>

        <div class="flex items-center gap-3">
            <x-ui.button type="submit">{{ $this->editing ? 'Save changes' : 'Create scheme' }}</x-ui.button>
            <x-ui.button variant="ghost" :href="$this->editing ? route('commission-schemes.show', $scheme) : route('commission-schemes.index')" wire:navigate>Cancel</x-ui.button>
        </div>
    </form>
</div>
