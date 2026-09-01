@php
    // M0: only the dashboard is live. The remaining groups mirror the legacy
    // CRM's workflow and are intentionally inert placeholders until their
    // milestones land — do NOT wire these to routes yet.
    $primaryNav = [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home'],
    ];

    $upcomingNav = [
        'Sales' => ['Projects / Sites', 'Blocks', 'Plots', 'Buyers'],
        'Transactions' => ['Booking', 'Payments', 'Installments', 'Cheques'],
        'Movements' => ['Plot Hold', 'Plot Transfer', 'Plot Interchange', 'Ownership Transfer', 'Payment Transfer', 'Cancellation'],
        'Closing' => ['Registry', 'Possession', 'Reports'],
        'Network' => ['Promoters', 'Associates'],
    ];
@endphp

{{-- Mobile backdrop --}}
<div x-show="sidebarOpen"
     x-transition.opacity
     @click="sidebarOpen = false"
     class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden"
     style="display:none"></div>

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
                    <a href="{{ route($item['route']) }}"
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

        @foreach ($upcomingNav as $group => $links)
            <div>
                <p class="px-3 text-xs font-semibold uppercase tracking-wider text-(--content-muted)/70">
                    {{ $group }}
                </p>
                <ul class="mt-1 space-y-0.5">
                    @foreach ($links as $link)
                        <li>
                            <span class="flex cursor-not-allowed items-center justify-between rounded-lg px-3 py-1.5 text-(--content-muted)/60"
                                  title="Available in a later milestone">
                                {{ $link }}
                                <x-ui.badge size="sm" variant="muted">soon</x-ui.badge>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>
</aside>
