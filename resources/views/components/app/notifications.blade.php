{{-- Notification placeholder — real notification stream arrives in a later milestone. --}}
<div x-data="{ open: false }" class="relative">
    <button type="button"
            @click="open = ! open"
            @click.outside="open = false"
            class="relative rounded-lg p-2 text-(--content-muted) hover:bg-(--surface-muted)"
            aria-label="Notifications">
        <x-app.icon name="bell" class="size-5" />
        <span class="absolute right-1.5 top-1.5 size-2 rounded-full bg-(--brand-accent)"></span>
    </button>

    <div x-show="open" x-transition style="display:none"
         class="absolute right-0 mt-2 w-72 rounded-xl border border-(--border) bg-(--surface) p-2 shadow-lg">
        <p class="px-2 py-1.5 text-xs font-semibold uppercase tracking-wider text-(--content-muted)">Notifications</p>
        <x-ui.empty-state
            title="You're all caught up"
            description="Notifications will appear here once the relevant modules are live." />
    </div>
</div>
