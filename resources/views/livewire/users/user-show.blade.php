<div class="space-y-6">
    <x-ui.page-header :title="$user->name" :description="$user->email">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('users.index')" wire:navigate>Back</x-ui.button>
            @can('update', $user)
                <x-ui.button :href="route('users.edit', $user)" wire:navigate>Edit</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Account" class="lg:col-span-1">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between"><dt class="text-(--content-muted)">Status</dt>
                    <dd><x-ui.badge :variant="$user->status->color()">{{ $user->status->label() }}</x-ui.badge></dd></div>
                <div class="flex justify-between"><dt class="text-(--content-muted)">Mobile</dt><dd>{{ $user->mobile ?: '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-(--content-muted)">Last login</dt>
                    <dd>{{ $user->last_login_at?->format('d M Y H:i') ?? 'Never' }}</dd></div>
                <div class="flex justify-between"><dt class="text-(--content-muted)">Created</dt>
                    <dd>{{ $user->created_at->format('d M Y') }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card title="Roles" class="lg:col-span-2">
            @forelse ($user->roles as $role)
                <div class="mb-3 last:mb-0">
                    <x-ui.badge variant="brand">{{ $role->name }}</x-ui.badge>
                </div>
            @empty
                <x-ui.empty-state title="No roles assigned" />
            @endforelse
        </x-ui.card>
    </div>

    <x-ui.card title="Effective permissions" :subtitle="$permissions->count().' permissions (union of all roles)'">
        @if ($user->isSuperAdmin())
            <x-ui.alert variant="info">Super Admin — implicitly has every permission.</x-ui.alert>
        @elseif ($permissions->isEmpty())
            <x-ui.empty-state title="No permissions" />
        @else
            <div class="flex flex-wrap gap-1.5">
                @foreach ($permissions as $permission)
                    <x-ui.badge variant="muted" size="sm">{{ $permission }}</x-ui.badge>
                @endforeach
            </div>
        @endif
    </x-ui.card>
</div>
