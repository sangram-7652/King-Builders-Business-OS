@php
    $user = auth()->user();
    $name = $user?->name ?? 'Guest';
    $email = $user?->email ?? '';
    $initials = collect(explode(' ', $name))->filter()->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode('');
@endphp

<div x-data="{ open: false }" class="relative">
    <button type="button"
            @click="open = ! open"
            @click.outside="open = false"
            class="flex items-center gap-2 rounded-lg p-1 pr-2 hover:bg-(--surface-muted)">
        <span class="flex size-8 items-center justify-center rounded-full bg-(--brand-primary) text-xs font-semibold text-(--brand-primary-fg)">
            {{ strtoupper($initials ?: 'U') }}
        </span>
        <span class="hidden text-sm font-medium sm:block">{{ $name }}</span>
        <x-app.icon name="chevron-down" class="size-4 text-(--content-muted)" />
    </button>

    <div x-show="open" x-transition style="display:none"
         class="absolute right-0 mt-2 w-56 rounded-xl border border-(--border) bg-(--surface) p-1.5 shadow-lg">
        <div class="border-b border-(--border) px-3 py-2">
            <p class="truncate text-sm font-medium">{{ $name }}</p>
            <p class="truncate text-xs text-(--content-muted)">{{ $email }}</p>
            <div class="mt-1 flex flex-wrap gap-1">
                @foreach ($user?->roles ?? [] as $role)
                    <x-ui.badge variant="brand" size="sm">{{ $role->name }}</x-ui.badge>
                @endforeach
            </div>
        </div>
        <form method="POST" action="{{ route('logout') }}" class="pt-1">
            @csrf
            <button type="submit"
                    class="flex w-full items-center gap-2 rounded-lg px-3 py-1.5 text-left text-sm text-(--content) hover:bg-(--surface-muted)">
                <x-app.icon name="logout" class="size-4" /> Sign out
            </button>
        </form>
    </div>
</div>
