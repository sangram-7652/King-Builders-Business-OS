<div class="space-y-6">
    @php
        $crumbs = [['label' => 'Channel Partners', 'url' => route('partners.index')]];
        $crumbs[] = $this->editing
            ? ['label' => $partner->displayName(), 'url' => route('partners.show', $partner)]
            : ['label' => 'New partner'];
        if ($this->editing) $crumbs[] = ['label' => 'Edit'];
    @endphp
    <x-ui.breadcrumb :items="$crumbs" />

    <x-ui.page-header
        :title="$this->editing ? 'Edit partner' : 'New channel partner'"
        :description="$this->editing ? $partner->partner_code : 'The partner code is generated automatically. The partner starts as a draft.'" />

    <form wire:submit="save" class="space-y-6">
        <x-ui.card title="Identity">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.select label="Partner type" wire:model.live="type" :options="$types" required :error="$errors->first('type')" />
                <x-ui.input label="Name" wire:model="name" required :error="$errors->first('name')"
                    hint="Person's name, or the primary name of the firm." />
                <x-ui.input label="Company / firm name" wire:model="company_name" :error="$errors->first('company_name')" />
                <x-ui.input label="Contact person" wire:model="contact_person" :error="$errors->first('contact_person')" />
                <x-ui.input label="Phone" wire:model="phone" required :error="$errors->first('phone')" />
                <x-ui.input label="Alternate phone" wire:model="alternate_phone" :error="$errors->first('alternate_phone')" />
                <x-ui.input type="email" label="Email" wire:model="email" :error="$errors->first('email')" />
                <x-ui.input label="RERA registration no." wire:model="rera_number" :error="$errors->first('rera_number')" />
            </div>
        </x-ui.card>

        <x-ui.card title="Address">
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2"><x-ui.input label="Address" wire:model="address" :error="$errors->first('address')" /></div>
                <x-ui.select label="State" wire:model.live="state_id" placeholder="—" :options="$states->toArray()" :error="$errors->first('state_id')" />
                <x-ui.select label="City" wire:model="city_id" placeholder="—" :options="$cities->toArray()" :disabled="$state_id === ''" :error="$errors->first('city_id')" />
                <x-ui.input label="Pincode" wire:model="pincode" :error="$errors->first('pincode')" />
            </div>
        </x-ui.card>

        <x-ui.card title="KYC identifier" subtitle="Stored encrypted at rest. Upload the supporting document from the partner's KYC screen.">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input label="PAN" wire:model="pan_number" placeholder="AAAAA9999A" :error="$errors->first('pan_number')" />
            </div>
        </x-ui.card>

        <x-ui.card title="Payout bank details" subtitle="Operational reference for commission payouts only — this is not an accounting ledger. Stored encrypted at rest.">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input label="Account holder name" wire:model="bank_account_name" :error="$errors->first('bank_account_name')" />
                <x-ui.input label="Account number" wire:model="bank_account_number" :error="$errors->first('bank_account_number')" />
                <x-ui.input label="IFSC" wire:model="bank_ifsc" placeholder="ABCD0123456" :error="$errors->first('bank_ifsc')" />
                <x-ui.input label="Bank name" wire:model="bank_name" :error="$errors->first('bank_name')" />
            </div>
        </x-ui.card>

        <x-ui.card title="Notes">
            <x-ui.textarea label="Internal notes" wire:model="notes" rows="3" :error="$errors->first('notes')" />
        </x-ui.card>

        <div class="flex items-center gap-3">
            <x-ui.button type="submit">{{ $this->editing ? 'Save changes' : 'Create partner' }}</x-ui.button>
            <x-ui.button variant="ghost" :href="$this->editing ? route('partners.show', $partner) : route('partners.index')" wire:navigate>Cancel</x-ui.button>
        </div>
    </form>
</div>
