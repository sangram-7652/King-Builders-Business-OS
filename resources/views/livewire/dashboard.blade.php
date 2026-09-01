<div class="space-y-6">
    <x-ui.page-header
        title="Dashboard"
        description="King Builders Business OS — M0 Foundation is in place.">
        <x-slot:actions>
            <x-ui.button variant="secondary" size="sm" wire:click="$refresh">Refresh</x-ui.button>
            <x-ui.button size="sm" x-on:click="$dispatch('toast', { message: 'Toasts are wired up ✅', variant: 'success' })">
                Test toast
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Foundation health --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($health as $item)
            <x-ui.stat-card
                :label="$item['label']"
                :value="$item['ok'] ? 'OK' : 'FAIL'"
                :delta="$item['detail']"
                :trend="$item['ok'] ? 'up' : 'down'"
                :icon="$item['ok'] ? 'home' : 'bell'" />
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="What's next" subtitle="Milestone roadmap" class="lg:col-span-2">
            <ol class="space-y-3 text-sm">
                <li class="flex items-center gap-3">
                    <x-ui.badge variant="success">Done</x-ui.badge>
                    <span><strong>M0</strong> — Docker, Laravel, Blade + Livewire + Tailwind, admin shell, UI kit, Pest</span>
                </li>
                <li class="flex items-center gap-3">
                    <x-ui.badge variant="brand">Next</x-ui.badge>
                    <span><strong>M1</strong> — Authentication &amp; RBAC</span>
                </li>
                <li class="flex items-center gap-3">
                    <x-ui.badge variant="muted">Later</x-ui.badge>
                    <span>Projects → Blocks → Plots → Buyers → Booking → Payments → Registry → Possession → Reports</span>
                </li>
            </ol>
        </x-ui.card>

        <x-ui.card title="Environment">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-(--content-muted)">Laravel</dt><dd>{{ app()->version() }}</dd></div>
                <div class="flex justify-between"><dt class="text-(--content-muted)">PHP</dt><dd>{{ PHP_VERSION }}</dd></div>
                <div class="flex justify-between"><dt class="text-(--content-muted)">Environment</dt><dd>{{ app()->environment() }}</dd></div>
                <div class="flex justify-between"><dt class="text-(--content-muted)">Debug</dt><dd>{{ config('app.debug') ? 'on' : 'off' }}</dd></div>
            </dl>
        </x-ui.card>
    </div>

    <x-ui.card title="UI kit" subtitle="Reusable components available to every module">
        <a href="{{ route('ui-kit') }}" class="text-sm font-medium text-(--brand-primary) hover:underline">
            Open the component gallery →
        </a>
    </x-ui.card>
</div>
