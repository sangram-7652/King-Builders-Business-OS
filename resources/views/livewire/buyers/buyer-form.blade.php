<div class="space-y-6">
    @php
        $crumbs = [['label' => 'Buyers', 'url' => route('buyers.index')]];
        $crumbs[] = $this->editing
            ? ['label' => $buyer->fullName(), 'url' => route('buyers.show', $buyer)]
            : ['label' => 'New buyer'];
        if ($this->editing) $crumbs[] = ['label' => 'Edit'];
    @endphp
    <x-ui.breadcrumb :items="$crumbs" />

    <x-ui.page-header
        :title="$this->editing ? 'Edit buyer' : 'New buyer'"
        :description="$this->editing ? $buyer->customer_code : 'The customer code is generated automatically.'" />

    @if ($this->duplicates->isNotEmpty())
        <x-ui.alert variant="warning" title="Similar {{ Str::plural('buyer', $this->duplicates->count()) }} on record">
            <ul class="mt-1 space-y-1 text-sm">
                @foreach ($this->duplicates as $dup)
                    <li>
                        <a href="{{ route('buyers.show', $dup) }}" wire:navigate class="font-medium text-(--brand-primary) hover:underline">{{ $dup->customer_code }}</a>
                        <span class="text-(--content-muted)"> — {{ $dup->fullName() }} · {{ $dup->phone }}</span>
                    </li>
                @endforeach
            </ul>
            <p class="mt-2 text-xs">Contact details can legitimately be shared. Review before adding another record.</p>
        </x-ui.alert>
    @endif

    <form wire:submit="save" class="space-y-6">
        <x-ui.card title="Personal">
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.input label="First name" wire:model="first_name" required :error="$errors->first('first_name')" />
                <x-ui.input label="Middle name" wire:model="middle_name" :error="$errors->first('middle_name')" />
                <x-ui.input label="Last name" wire:model="last_name" :error="$errors->first('last_name')" />
                <x-ui.input label="Phone" wire:model.live.debounce.400ms="phone" required :error="$errors->first('phone')" />
                <x-ui.input label="Alternate phone" wire:model="alternate_phone" :error="$errors->first('alternate_phone')" />
                <x-ui.input type="email" label="Email" wire:model.live.debounce.400ms="email" :error="$errors->first('email')" />
                <x-ui.date-input label="Date of birth" wire:model="date_of_birth" :error="$errors->first('date_of_birth')" />
                <x-ui.select label="Gender" wire:model="gender" placeholder="—" :options="$genders" :error="$errors->first('gender')" />
                <x-ui.input label="Occupation" wire:model="occupation" :error="$errors->first('occupation')" />
            </div>
        </x-ui.card>

        <x-ui.card title="Address">
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2"><x-ui.input label="Address" wire:model="address" :error="$errors->first('address')" /></div>
                <x-ui.select label="State" wire:model.live="state_id" placeholder="—" :options="$states->toArray()" :error="$errors->first('state_id')" />
                <x-ui.select label="City" wire:model="city_id" placeholder="—" :options="$this->cities->toArray()" :disabled="$state_id === ''" :error="$errors->first('city_id')" />
                <x-ui.input label="Pincode" wire:model="pincode" :error="$errors->first('pincode')" />
            </div>
        </x-ui.card>

        @if ($canEditDocuments)
            <x-ui.card title="KYC identifiers" subtitle="Stored encrypted at rest. Leave blank to keep the existing value.">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input label="PAN" wire:model="pan_number" placeholder="AAAAA9999A"
                        :hint="$this->editing ? 'Enter a new value to replace the stored one.' : null"
                        :error="$errors->first('pan_number')" />
                    <x-ui.input label="Aadhaar" wire:model="aadhaar_number" placeholder="1234 5678 9012"
                        :error="$errors->first('aadhaar_number')" />
                </div>
            </x-ui.card>
        @endif

        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary" :href="$this->editing ? route('buyers.show', $buyer) : route('buyers.index')" wire:navigate>Cancel</x-ui.button>
            <x-ui.button type="submit" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">{{ $this->editing ? 'Save changes' : 'Create buyer' }}</span>
                <span wire:loading wire:target="save">Saving…</span>
            </x-ui.button>
        </div>
    </form>
</div>
