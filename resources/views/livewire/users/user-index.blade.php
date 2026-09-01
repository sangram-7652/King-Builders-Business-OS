<div class="space-y-6">
    <x-ui.page-header title="Users" description="Manage who can access the Business OS and what they can do.">
        <x-slot:actions>
            @can('create', App\Models\User::class)
                <x-ui.button :href="route('users.create')" wire:navigate>New user</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :padding="false">
        {{-- Filters --}}
        <div class="flex flex-col gap-3 border-b border-(--border) p-4 sm:flex-row sm:items-end">
            <div class="flex-1">
                <x-ui.input
                    label="Search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Name, email or mobile…" />
            </div>
            <div class="w-full sm:w-44">
                <x-ui.select label="Status" wire:model.live="status" placeholder="All statuses" :options="$statuses" />
            </div>
            <div class="w-full sm:w-52">
                <x-ui.select label="Role" wire:model.live="role" placeholder="All roles">
                    @foreach ($roles as $r)
                        <option value="{{ $r }}">{{ $r }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            @if ($search !== '' || $status !== '' || $role !== '')
                <x-ui.button variant="ghost" wire:click="clearFilters">Clear</x-ui.button>
            @endif
        </div>

        {{-- Table --}}
        @if ($users->isEmpty())
            <div class="p-6">
                <x-ui.empty-state title="No users found" description="Try adjusting your search or filters." icon="user" />
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="bg-(--surface-muted) text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Mobile</th>
                            <th class="px-4 py-3">Roles</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Last login</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($users as $user)
                            <tr wire:key="user-{{ $user->id }}" class="hover:bg-(--surface-muted)/50">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-(--content)">{{ $user->name }}</div>
                                    <div class="text-xs text-(--content-muted)">{{ $user->email }}</div>
                                </td>
                                <td class="px-4 py-3 text-(--content-muted)">{{ $user->mobile ?: '—' }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        @forelse ($user->roles as $role)
                                            <x-ui.badge variant="brand" size="sm">{{ $role->name }}</x-ui.badge>
                                        @empty
                                            <span class="text-xs text-(--content-muted)">No role</span>
                                        @endforelse
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <x-ui.badge :variant="$user->status->color()">{{ $user->status->label() }}</x-ui.badge>
                                </td>
                                <td class="px-4 py-3 text-(--content-muted)">
                                    {{ $user->last_login_at?->diffForHumans() ?? 'Never' }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        @can('view', $user)
                                            <x-ui.button variant="ghost" size="sm" :href="route('users.show', $user)" wire:navigate>View</x-ui.button>
                                        @endcan
                                        @can('update', $user)
                                            <x-ui.button variant="ghost" size="sm" :href="route('users.edit', $user)" wire:navigate>Edit</x-ui.button>
                                        @endcan
                                        @can('toggleStatus', $user)
                                            <x-ui.button
                                                variant="ghost" size="sm"
                                                wire:click="toggleStatus({{ $user->id }})"
                                                wire:confirm="{{ $user->isActive() ? 'Deactivate' : 'Activate' }} {{ $user->name }}?">
                                                {{ $user->isActive() ? 'Deactivate' : 'Activate' }}
                                            </x-ui.button>
                                        @endcan
                                        @can('delete', $user)
                                            <x-ui.button
                                                variant="ghost" size="sm"
                                                class="text-red-600"
                                                wire:click="delete({{ $user->id }})"
                                                wire:confirm="Permanently delete {{ $user->name }}? This cannot be undone.">
                                                Delete
                                            </x-ui.button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="p-3">
                {{ $users->links() }}
            </div>
        @endif
    </x-ui.card>
</div>
