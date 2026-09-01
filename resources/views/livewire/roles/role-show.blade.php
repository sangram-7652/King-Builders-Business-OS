<div class="space-y-6">
    <x-ui.page-header :title="$role->name" :description="$role->isSystem() ? 'System role' : 'Custom role'">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('roles.index')" wire:navigate>Back</x-ui.button>
            @can('update', $role)
                @unless ($role->isSuperAdmin())
                    <x-ui.button :href="route('roles.edit', $role)" wire:navigate>Edit</x-ui.button>
                @endunless
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card label="Users with this role" :value="$userCount" />
        <x-ui.stat-card label="Permissions" :value="$role->isSuperAdmin() ? 'All' : $role->permissions->count()" />
        <x-ui.stat-card label="Type" :value="$role->isSystem() ? 'System' : 'Custom'" />
    </div>

    @if ($role->isSuperAdmin())
        <x-ui.alert variant="info">Super Admin implicitly holds every permission, including permissions for modules that don't exist yet.</x-ui.alert>
    @elseif (empty($groups))
        <x-ui.empty-state title="No permissions assigned" />
    @else
        <div class="space-y-4">
            @foreach ($groups as $data)
                <x-ui.card :title="$data['group']->label()">
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($data['permissions'] as $permission)
                            <x-ui.badge variant="muted" size="sm">{{ $permission->value }}</x-ui.badge>
                        @endforeach
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    @endif
</div>
