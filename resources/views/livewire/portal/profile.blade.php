<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold">My profile</h1>
            <p class="text-sm text-(--content-muted)">{{ $customer->customer_code }}</p>
        </div>
        @unless ($editing)
            <x-ui.button variant="secondary" size="sm" wire:click="edit">Edit contact details</x-ui.button>
        @endunless
    </div>

    <x-ui.card title="Personal details">
        <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div><dt class="text-(--content-muted)">Name</dt><dd>{{ $customer->fullName() }}</dd></div>
            <div><dt class="text-(--content-muted)">Date of birth</dt><dd>{{ $customer->date_of_birth?->format('d M Y') ?? '—' }}</dd></div>
            <div><dt class="text-(--content-muted)">Email</dt><dd>{{ $customer->email }}</dd></div>
            <div><dt class="text-(--content-muted)">PAN</dt><dd>{{ $customer->maskedPan() ?? '—' }}</dd></div>
        </dl>
        <p class="mt-3 text-xs text-(--content-muted)">To change your name, email or ID details, please raise a support request.</p>
    </x-ui.card>

    <x-ui.card title="Contact details">
        @if ($editing)
            <form wire:submit="save" class="grid gap-5 sm:grid-cols-2">
                <x-ui.input label="Phone" wire:model="phone" required :error="$errors->first('phone')" />
                <x-ui.input label="Alternate phone" wire:model="alternate_phone" :error="$errors->first('alternate_phone')" />
                <div class="sm:col-span-2"><x-ui.input label="Address" wire:model="address" :error="$errors->first('address')" /></div>
                <x-ui.select label="State" wire:model.live="state_id" placeholder="—" :options="$states->toArray()" :error="$errors->first('state_id')" />
                <x-ui.select label="City" wire:model="city_id" placeholder="—" :options="$cities->toArray()" :disabled="$state_id === ''" :error="$errors->first('city_id')" />
                <x-ui.input label="Pincode" wire:model="pincode" :error="$errors->first('pincode')" />
                <x-ui.input label="Occupation" wire:model="occupation" :error="$errors->first('occupation')" />
                <div class="sm:col-span-2 flex gap-2">
                    <x-ui.button type="submit">Save changes</x-ui.button>
                    <x-ui.button type="button" variant="ghost" wire:click="cancel">Cancel</x-ui.button>
                </div>
            </form>
        @else
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="text-(--content-muted)">Phone</dt><dd>{{ $customer->phone }}</dd></div>
                <div><dt class="text-(--content-muted)">Alternate phone</dt><dd>{{ $customer->alternate_phone ?: '—' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-(--content-muted)">Address</dt><dd>{{ $customer->address ?: '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Location</dt><dd>{{ $customer->locationLabel() }}</dd></div>
                <div><dt class="text-(--content-muted)">Pincode</dt><dd>{{ $customer->pincode ?: '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Occupation</dt><dd>{{ $customer->occupation ?: '—' }}</dd></div>
            </dl>
        @endif
    </x-ui.card>
</div>
