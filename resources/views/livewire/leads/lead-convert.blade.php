<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Leads', 'url' => route('leads.index')],
        ['label' => $lead->name, 'url' => route('leads.show', $lead)],
        ['label' => 'Convert'],
    ]" />

    <x-ui.page-header title="Convert lead to buyer" :description="$lead->name.' · '.$lead->phone" />

    @if ($notQualified)
        <x-ui.alert variant="danger" title="Lead is not qualified">
            Only a <strong>Qualified</strong> lead can be converted. Update the lead status first.
        </x-ui.alert>
    @else
        {{-- Existing buyer matches --}}
        @if ($this->buyerMatches->isNotEmpty())
            <x-ui.card title="Existing buyer found" subtitle="This phone or email already belongs to a buyer. Records are never merged automatically — choose one.">
                <div class="space-y-2">
                    @foreach ($this->buyerMatches as $b)
                        <label class="flex items-center justify-between gap-4 rounded-lg border px-4 py-3 text-sm {{ $mode === 'existing' && $existingBuyerId === $b->id ? 'border-(--brand-primary) bg-(--brand-primary)/5' : 'border-(--border)' }}">
                            <span>
                                <span class="font-medium text-(--content)">{{ $b->customer_code }}</span> — {{ $b->fullName() }}
                                <span class="text-(--content-muted)"> · {{ $b->phone }}@if ($b->email) · {{ $b->email }} @endif</span>
                            </span>
                            <x-ui.button type="button" size="sm"
                                :variant="$mode === 'existing' && $existingBuyerId === $b->id ? 'primary' : 'secondary'"
                                wire:click="useExisting({{ $b->id }})">
                                {{ $mode === 'existing' && $existingBuyerId === $b->id ? 'Selected' : 'Use this buyer' }}
                            </x-ui.button>
                        </label>
                    @endforeach
                </div>
                <div class="mt-3">
                    <x-ui.button type="button" variant="ghost" size="sm" wire:click="useNew">Create a new buyer instead →</x-ui.button>
                </div>
                @error('existingBuyerId') <p class="mt-2 text-sm font-medium text-red-600">{{ $message }}</p> @enderror
            </x-ui.card>
        @endif

        {{-- New buyer form --}}
        @if ($mode === 'new')
            <form wire:submit="convert" class="space-y-6">
                <x-ui.card title="New buyer details">
                    <div class="grid gap-5 sm:grid-cols-3">
                        <x-ui.input label="First name" wire:model="first_name" required :error="$errors->first('first_name')" />
                        <x-ui.input label="Middle name" wire:model="middle_name" :error="$errors->first('middle_name')" />
                        <x-ui.input label="Last name" wire:model="last_name" :error="$errors->first('last_name')" />
                        <x-ui.input label="Phone" wire:model="phone" required :error="$errors->first('phone')" />
                        <x-ui.input label="Alternate phone" wire:model="alternate_phone" :error="$errors->first('alternate_phone')" />
                        <x-ui.input type="email" label="Email" wire:model="email" :error="$errors->first('email')" />
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

                @can('buyers.documents')
                    <x-ui.card title="KYC identifiers" subtitle="Stored encrypted. Optional — can be added later.">
                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-ui.input label="PAN" wire:model="pan_number" placeholder="AAAAA9999A" :error="$errors->first('pan_number')" />
                            <x-ui.input label="Aadhaar" wire:model="aadhaar_number" placeholder="1234 5678 9012" :error="$errors->first('aadhaar_number')" />
                        </div>
                    </x-ui.card>
                @endcan

                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" :href="route('leads.show', $lead)" wire:navigate>Cancel</x-ui.button>
                    <x-ui.button type="submit" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="convert">Create buyer &amp; convert</span>
                        <span wire:loading wire:target="convert">Converting…</span>
                    </x-ui.button>
                </div>
            </form>
        @else
            <div class="flex justify-end gap-2">
                <x-ui.button variant="secondary" :href="route('leads.show', $lead)" wire:navigate>Cancel</x-ui.button>
                <x-ui.button wire:click="convert" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="convert">Convert using selected buyer</span>
                    <span wire:loading wire:target="convert">Converting…</span>
                </x-ui.button>
            </div>
        @endif
    @endif
</div>
