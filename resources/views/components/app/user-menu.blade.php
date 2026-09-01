{{-- User menu placeholder — authentication (login/logout, real user) lands in M1. --}}
@php
    $user = auth()->user();
    $name = $user->name ?? 'Guest User';
    $email = $user->email ?? 'not-authenticated@kingbuilders.test';
    $initials = strtoupper(mb_substr($name, 0, 1) . (str_contains($name, ' ') ? mb_substr(strrchr($name, ' ') ?: '', 1, 1) : ''));
@endphp

<div x-data="{ open: false }" class="relative">
    <button type="button"
            @click="open = ! open"
            @click.outside="open = false"
            class="flex items-center gap-2 rounded-lg p-1 pr-2 hover:bg-(--surface-muted)">
        <span class="flex size-8 items-center justify-center rounded-full bg-(--brand-primary) text-xs font-semibold text-(--brand-primary-fg)">
            {{ $initials ?: 'GU' }}
        </span>
        <span class="hidden text-sm font-medium sm:block">{{ $name }}</span>
        <x-app.icon name="chevron-down" class="size-4 text-(--content-muted)" />
    </button>

    <div x-show="open" x-transition style="display:none"
         class="absolute right-0 mt-2 w-56 rounded-xl border border-(--border) bg-(--surface) p-1.5 shadow-lg">
        <div class="border-b border-(--border) px-3 py-2">
            <p class="truncate text-sm font-medium">{{ $name }}</p>
            <p class="truncate text-xs text-(--content-muted)">{{ $email }}</p>
        </div>
        <div class="py-1">
            <span class="flex cursor-not-allowed items-center gap-2 rounded-lg px-3 py-1.5 text-sm text-(--content-muted)/60">
                <x-app.icon name="user" class="size-4" /> Profile
                <x-ui.badge size="sm" variant="muted" class="ml-auto">M1</x-ui.badge>
            </span>
            <span class="flex cursor-not-allowed items-center gap-2 rounded-lg px-3 py-1.5 text-sm text-(--content-muted)/60">
                <x-app.icon name="logout" class="size-4" /> Sign out
                <x-ui.badge size="sm" variant="muted" class="ml-auto">M1</x-ui.badge>
            </span>
        </div>
    </div>
</div>
