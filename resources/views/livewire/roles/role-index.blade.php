<div class="space-y-6">
    <x-ui.page-header title="Roles &amp; Permissions" description="Roles bundle permissions. Assign roles to users on the Users screen.">
        <x-slot:actions>
            @can('create', App\Models\Role::class)
                <x-ui.button :href="route('roles.create')" wire:navigate>New role</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :padding="false">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-(--border) text-sm">
                <thead class="bg-(--surface-muted) text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                    <tr>
                        <th class="px-4 py-3">Role</th>
                        <th class="px-4 py-3">Permissions</th>
                        <th class="px-4 py-3">Users</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-(--border)">
                    @foreach ($roles as $role)
                        <tr wire:key="role-{{ $role->id }}" class="hover:bg-(--surface-muted)/50">
                            <td class="px-4 py-3 font-medium text-(--content)">{{ $role->name }}</td>
                            <td class="px-4 py-3 text-(--content-muted)">
                                {{ $role->isSuperAdmin() ? 'All (implicit)' : $role->permissions_count }}
                            </td>
                            <td class="px-4 py-3 text-(--content-muted)">{{ $role->users_count }}</td>
                            <td class="px-4 py-3">
                                <x-ui.badge :variant="$role->isSystem() ? 'info' : 'muted'" size="sm">
                                    {{ $role->isSystem() ? 'System' : 'Custom' }}
                                </x-ui.badge>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    @can('view', $role)
                                        <x-ui.button variant="ghost" size="sm" :href="route('roles.show', $role)" wire:navigate>View</x-ui.button>
                                    @endcan
                                    @can('update', $role)
                                        <x-ui.button variant="ghost" size="sm" :href="route('roles.edit', $role)" wire:navigate>Edit</x-ui.button>
                                    @endcan
                                    @can('delete', $role)
                                        <x-ui.button
                                            variant="ghost" size="sm" class="text-red-600"
                                            wire:click="delete({{ $role->id }})"
                                            wire:confirm="Delete the {{ $role->name }} role?">Delete</x-ui.button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
