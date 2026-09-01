@php
    use App\Enums\Permission;
    use App\Enums\PermissionGroup;

    $user = auth()->user();

    // M1: live navigation. Each entry is gated by a permission.
    $primaryNav = array_values(array_filter([
        ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home', 'can' => true],
        ['label' => 'Users', 'route' => 'users.index', 'icon' => 'user', 'can' => $user?->can(Permission::UsersView->value)],
        ['label' => 'Roles & Permissions', 'route' => 'roles.index', 'icon' => 'inbox', 'can' => $user?->can(Permission::RolesView->value)],
    ], fn ($item) => $item['can']));

    // Future modules — shown only if the user holds at least one permission in
    // that area, and rendered inert until the module ships.
    $upcoming = [];
    foreach (Permission::grouped() as $groupValue => $perms) {
        $group = PermissionGroup::from($groupValue);
        if (in_array($group, [PermissionGroup::Administration, PermissionGroup::Settings], true)) {
            continue;
        }
        $names = array_map(fn (Permission $p) => $p->value, $perms);
        if ($user?->canAny($names)) {
            $modules = collect($perms)->map(fn (Permission $p) => \Illuminate\Support\Str::headline($p->module()))->unique()->values()->all();
            $upcoming[$group->label()] = $modules;
        }
    }
@endphp

{{-- Mobile backdrop --}}
<div x-show="sidebarOpen" x-transition.opacity @click="sidebarOpen = false"
     class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden" style="display:none"></div>

<aside
    class="fixed inset-y-0 left-0 z-40 w-64 -translate-x-full overflow-y-auto border-r border-(--border) bg-(--surface) transition-transform duration-200 lg:static lg:translate-x-0"
    :class="sidebarOpen && 'translate-x-0'">

    <div class="flex h-16 items-center gap-3 border-b border-(--border) px-4">
        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-(--brand-primary) text-sm font-bold text-(--brand-primary-fg)">
            {{ $branding->initials() }}
        </span>
        <span class="truncate text-sm font-semibold">{{ $branding->name }}</span>
    </div>

    <nav class="space-y-6 p-4 text-sm">
        <ul class="space-y-1">
            @foreach ($primaryNav as $item)
                @php $active = request()->routeIs($item['route']); @endphp
                <li>
                    <a href="{{ route($item['route']) }}" wire:navigate
                       @class([
                           'flex items-center gap-3 rounded-lg px-3 py-2 font-medium transition',
                           'bg-(--brand-primary) text-(--brand-primary-fg)' => $active,
                           'text-(--content-muted) hover:bg-(--surface-muted) hover:text-(--content)' => ! $active,
                       ])
                       @if ($active) aria-current="page" @endif>
                        <x-app.icon :name="$item['icon']" class="size-5" />
                        {{ $item['label'] }}
                    </a>
                </li>
            @endforeach
        </ul>

        @foreach ($upcoming as $group => $modules)
            <div>
                <p class="px-3 text-xs font-semibold uppercase tracking-wider text-(--content-muted)/70">{{ $group }}</p>
                <ul class="mt-1 space-y-0.5">
                    @foreach ($modules as $module)
                        <li>
                            <span class="flex cursor-not-allowed items-center justify-between rounded-lg px-3 py-1.5 text-(--content-muted)/60"
                                  title="Available in a later milestone">
                                {{ $module }}
                                <x-ui.badge size="sm" variant="muted">soon</x-ui.badge>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>
</aside>
