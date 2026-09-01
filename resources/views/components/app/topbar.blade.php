@props(['breadcrumbs' => []])

<header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-(--border) bg-(--surface)/90 px-4 backdrop-blur sm:px-6 lg:px-8">

    {{-- Mobile sidebar toggle --}}
    <button type="button"
            @click="sidebarOpen = ! sidebarOpen"
            class="-ml-1 rounded-lg p-2 text-(--content-muted) hover:bg-(--surface-muted) lg:hidden"
            aria-label="Toggle navigation">
        <x-app.icon name="menu" class="size-5" />
    </button>

    {{-- Breadcrumbs --}}
    <div class="min-w-0 flex-1">
        <x-ui.breadcrumb :items="$breadcrumbs" />
    </div>

    {{-- Search placeholder --}}
    <div class="hidden md:block">
        <label class="relative block">
            <span class="sr-only">Search</span>
            <x-app.icon name="search" class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-(--content-muted)" />
            <input type="search" placeholder="Search…" disabled
                   class="w-56 rounded-lg border border-(--border) bg-(--surface-muted) py-1.5 pl-8 pr-3 text-sm text-(--content-muted) placeholder:text-(--content-muted)/60" />
        </label>
    </div>

    <x-app.notifications />
    <x-app.user-menu />
</header>
