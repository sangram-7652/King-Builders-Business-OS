<div class="space-y-6">
    <x-ui.page-header
        :title="$this->editing ? 'Edit role' : 'New role'"
        :description="$this->editing ? $role->name : 'Define a role and choose its permissions.'" />

    <form wire:submit="save" class="space-y-6">
        <x-ui.card title="Role">
            @if ($this->isSystemRole)
                <x-ui.alert variant="info" class="mb-4">
                    This is a system role — its name is fixed, but you can still adjust its permissions.
                </x-ui.alert>
            @endif
            <x-ui.input
                label="Role name"
                wire:model="name"
                :disabled="$this->isSystemRole"
                required
                :error="$errors->first('name')" />
        </x-ui.card>

        <x-ui.card title="Permissions" subtitle="Grouped by area. Ticking a group heading toggles all its permissions.">
            @error('permissions') <x-ui.alert variant="danger" class="mb-3">{{ $message }}</x-ui.alert> @enderror

            <div class="space-y-6">
                @foreach ($this->groupedPermissions as $groupValue => $data)
                    @php
                        $groupPerms = collect($data['permissions'])->map->value;
                        $allChecked = $groupPerms->every(fn ($p) => in_array($p, $permissions, true));
                    @endphp
                    <div class="rounded-lg border border-(--border)">
                        <label class="flex items-center gap-2 border-b border-(--border) bg-(--surface-muted) px-4 py-2 text-sm font-semibold">
                            <input
                                type="checkbox"
                                class="rounded border-(--border)"
                                @checked($allChecked)
                                wire:click="toggleGroup('{{ $groupValue }}', $event.target.checked)">
                            {{ $data['group']->label() }}
                        </label>
                        <div class="grid gap-2 p-4 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($data['permissions'] as $permission)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" value="{{ $permission->value }}" wire:model.live="permissions" class="rounded border-(--border)">
                                    <span>{{ $permission->label() }}
                                        <span class="text-xs text-(--content-muted)">({{ $permission->value }})</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="secondary" :href="route('roles.index')" wire:navigate>Cancel</x-ui.button>
            <x-ui.button type="submit">{{ $this->editing ? 'Save changes' : 'Create role' }}</x-ui.button>
        </div>
    </form>
</div>
