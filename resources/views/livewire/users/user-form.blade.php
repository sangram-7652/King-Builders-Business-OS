<div class="space-y-6">
    <x-ui.page-header
        :title="$this->editing ? 'Edit user' : 'New user'"
        :description="$this->editing ? $user->email : 'Create a user account and assign roles.'" />

    <form wire:submit="save" class="space-y-6">
        <x-ui.card title="Details">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input label="Full name" wire:model="name" required :error="$errors->first('name')" />
                <x-ui.input type="email" label="Email address" wire:model="email" required :error="$errors->first('email')" />
                <x-ui.input label="Mobile" wire:model="mobile" :error="$errors->first('mobile')" hint="Optional" />
                <x-ui.select label="Status" wire:model="status" :options="$statuses" :error="$errors->first('status')" />
            </div>
        </x-ui.card>

        <x-ui.card title="Roles" subtitle="A user can hold more than one role. Permissions are the union of all assigned roles.">
            @error('roles') <x-ui.alert variant="danger" class="mb-3">{{ $message }}</x-ui.alert> @enderror
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($availableRoles as $role)
                    <label class="flex items-center gap-2 rounded-lg border border-(--border) px-3 py-2 text-sm hover:bg-(--surface-muted)">
                        <input type="checkbox" value="{{ $role }}" wire:model="roles" class="rounded border-(--border)">
                        {{ $role }}
                    </label>
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card
            :title="$this->editing ? 'Set new password' : 'Password'"
            :subtitle="$this->editing ? 'Leave blank to keep the current password.' : null">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input type="password" label="Password" wire:model="password" autocomplete="new-password" :error="$errors->first('password')" />
                <x-ui.input type="password" label="Confirm password" wire:model="password_confirmation" autocomplete="new-password" />
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary" :href="route('users.index')" wire:navigate>Cancel</x-ui.button>
            <x-ui.button type="submit">{{ $this->editing ? 'Save changes' : 'Create user' }}</x-ui.button>
        </div>
    </form>
</div>
