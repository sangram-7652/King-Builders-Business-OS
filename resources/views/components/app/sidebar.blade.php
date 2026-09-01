@php
    use App\Enums\Permission;
    use App\Enums\PermissionGroup;
    use App\Masters\MasterRegistry;

    $user = auth()->user();

    // --- Primary (always-visible workspace) --------------------------------
    $primaryNav = array_values(array_filter([
        ['label' => 'Dashboard', 'route' => 'dashboard', 'params' => [], 'icon' => 'home', 'can' => true],
    ], fn ($item) => $item['can']));

    // --- Administration ---------------------------------------------------
    $adminNav = array_values(array_filter([
        ['label' => 'Users', 'route' => 'users.index', 'params' => [], 'icon' => 'user', 'can' => (bool) $user?->can(Permission::UsersView->value)],
        ['label' => 'Roles & Permissions', 'route' => 'roles.index', 'params' => [], 'icon' => 'inbox', 'can' => (bool) $user?->can(Permission::RolesView->value)],
    ], fn ($item) => $item['can']));

    // --- Settings › Master Data -----------------------------------------
    $canMasters = (bool) $user?->can(Permission::MastersView->value);
    $masterGroups = $canMasters ? MasterRegistry::grouped() : [];

    // --- Future modules (inert roadmap) --------------------------------
    $upcoming = [];
    foreach (Permission::grouped() as $groupValue => $perms) {
        $group = PermissionGroup::from($groupValue);
        if (in_array($group, [PermissionGroup::Administration, PermissionGroup::MasterData, PermissionGroup::Settings], true)) {
            continue;
        }
        $names = array_map(fn (Permission $p) => $p->value, $perms);
        if ($user?->canAny($names)) {
            $modules = collect($perms)->map(fn (Permission $p) => \Illuminate\Support\Str::headline($p->module()))->unique()->values()->all();
            $upcoming[$group->label()] = $modules;
        }
    }

    $linkClasses = fn (bool $active) => \Illuminate\Support\Arr::toCssClasses([
        'flex items-center gap-3 rounded-lg px-3 py-2 font-medium transition',
        'bg-(--brand-primary) text-(--brand-primary-fg)' => $active,
        'text-(--content-muted) hover:bg-(--surface-muted) hover:text-(--content)' => ! $active,
    ]);
@endphp

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
                    <a href="{{ route($item['route'], $item['params']) }}" wire:navigate class="{{ $linkClasses($active) }}"
                       @if ($active) aria-current="page" @endif>
                        <x-app.icon :name="$item['icon']" class="size-5" />
                        {{ $item['label'] }}
                    </a>
                </li>
            @endforeach
        </ul>

        @if ($adminNav !== [])
            <div>
                <p class="px-3 text-xs font-semibold uppercase tracking-wider text-(--content-muted)/70">Administration</p>
                <ul class="mt-1 space-y-1">
                    @foreach ($adminNav as $item)
                        @php $active = request()->routeIs($item['route']); @endphp
                        <li>
                            <a href="{{ route($item['route'], $item['params']) }}" wire:navigate class="{{ $linkClasses($active) }}"
                               @if ($active) aria-current="page" @endif>
                                <x-app.icon :name="$item['icon']" class="size-5" />
                                {{ $item['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($canMasters)
            <div x-data="{ open: {{ request()->routeIs('masters.*') ? 'true' : 'false' }} }">
                <p class="px-3 text-xs font-semibold uppercase tracking-wider text-(--content-muted)/70">Settings</p>
                <button type="button" @click="open = ! open"
                    class="{{ $linkClasses(request()->routeIs('masters.dashboard')) }} mt-1 w-full">
                    <x-app.icon name="inbox" class="size-5" />
                    <span class="flex-1 text-left">Master Data</span>
                    <x-app.icon name="chevron-down" class="size-4 transition" ::class="open && 'rotate-180'" />
                </button>
                <div x-show="open" x-collapse class="mt-1 space-y-3 pl-3">
                    <a href="{{ route('masters.dashboard') }}" wire:navigate
                       class="block rounded-lg px-3 py-1.5 text-(--content-muted) hover:bg-(--surface-muted) hover:text-(--content) {{ request()->routeIs('masters.dashboard') ? 'font-semibold text-(--content)' : '' }}">
                        Overview
                    </a>
                    @foreach ($masterGroups as $groupValue => $resources)
                        <div>
                            <p class="px-3 text-[11px] font-semibold uppercase tracking-wider text-(--content-muted)/60">
                                {{ \App\Enums\Masters\MasterGroup::from($groupValue)->label() }}
                            </p>
                            <ul class="mt-0.5 space-y-0.5">
                                @foreach ($resources as $resource)
                                    @php $active = request()->routeIs('masters.*') && request()->route('resource') === $resource->slug(); @endphp
                                    <li>
                                        <a href="{{ route('masters.index', ['resource' => $resource->slug()]) }}" wire:navigate
                                           class="block rounded-lg px-3 py-1.5 {{ $active ? 'bg-(--brand-primary)/10 font-medium text-(--brand-primary)' : 'text-(--content-muted) hover:bg-(--surface-muted) hover:text-(--content)' }}">
                                            {{ $resource->pluralLabel() }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

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
