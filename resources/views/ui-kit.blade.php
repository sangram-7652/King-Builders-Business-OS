<x-layouts.app title="UI Kit" :breadcrumbs="[
    ['label' => 'Dashboard', 'url' => route('dashboard')],
    ['label' => 'UI Kit'],
]">
    <x-ui.page-header title="UI Kit" description="Foundation components — branding-aware, Tailwind-only." />

    <x-ui.card title="Buttons">
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button>Primary</x-ui.button>
            <x-ui.button variant="secondary">Secondary</x-ui.button>
            <x-ui.button variant="ghost">Ghost</x-ui.button>
            <x-ui.button variant="danger">Danger</x-ui.button>
            <x-ui.button :loading="true">Loading</x-ui.button>
        </div>
    </x-ui.card>

    <x-ui.card title="Form controls">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-ui.input label="Full name" name="demo_name" placeholder="Jane Buyer" hint="Shown on the agreement" />
            <x-ui.select label="Status" name="demo_status" placeholder="Select…" :options="['hold' => 'Hold', 'booked' => 'Booked', 'sold' => 'Sold']" />
            <x-ui.date-input label="Booking date" name="demo_date" />
            <x-ui.textarea label="Notes" name="demo_notes" placeholder="Internal remarks…" />
        </div>
    </x-ui.card>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="Badges">
            <div class="flex flex-wrap gap-2">
                <x-ui.badge variant="brand">Brand</x-ui.badge>
                <x-ui.badge variant="success">Success</x-ui.badge>
                <x-ui.badge variant="warning">Warning</x-ui.badge>
                <x-ui.badge variant="danger">Danger</x-ui.badge>
                <x-ui.badge variant="info">Info</x-ui.badge>
                <x-ui.badge variant="muted">Muted</x-ui.badge>
            </div>
        </x-ui.card>

        <x-ui.card title="Stat cards">
            <div class="grid grid-cols-2 gap-3">
                <x-ui.stat-card label="Plots" value="0" delta="M-later" trend="neutral" icon="home" />
                <x-ui.stat-card label="Buyers" value="0" delta="M-later" trend="neutral" icon="user" />
            </div>
        </x-ui.card>
    </div>

    <x-ui.card title="Alerts">
        <div class="space-y-3">
            <x-ui.alert variant="info" title="Heads up">This is an informational alert.</x-ui.alert>
            <x-ui.alert variant="success" dismissible>Saved successfully.</x-ui.alert>
            <x-ui.alert variant="warning">Careful with this one.</x-ui.alert>
            <x-ui.alert variant="danger" title="Error">Something went wrong.</x-ui.alert>
        </div>
    </x-ui.card>

    <x-ui.card title="Table">
        <x-ui.table :headers="['Plot', 'Block', 'Status', 'Buyer']">
            <tr>
                <td class="px-4 py-3">A-101</td>
                <td class="px-4 py-3">Block A</td>
                <td class="px-4 py-3"><x-ui.badge variant="success">Available</x-ui.badge></td>
                <td class="px-4 py-3 text-(--content-muted)">—</td>
            </tr>
            <tr>
                <td class="px-4 py-3">A-102</td>
                <td class="px-4 py-3">Block A</td>
                <td class="px-4 py-3"><x-ui.badge variant="warning">Hold</x-ui.badge></td>
                <td class="px-4 py-3 text-(--content-muted)">—</td>
            </tr>
        </x-ui.table>
    </x-ui.card>

    <x-ui.card title="Overlays">
        <div class="flex flex-wrap gap-3">
            <x-ui.button x-on:click="$dispatch('open-modal', 'demo-modal')">Open modal</x-ui.button>
            <x-ui.button variant="secondary" x-on:click="$dispatch('open-drawer', 'demo-drawer')">Open drawer</x-ui.button>
            <x-ui.button variant="danger" x-on:click="$dispatch('open-modal', 'demo-confirm')">Confirm dialog</x-ui.button>
        </div>

        <x-ui.modal name="demo-modal" title="Example modal">
            <p class="text-sm text-(--content-muted)">Modal body content goes here.</p>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="open = false">Close</x-ui.button>
                <x-ui.button x-on:click="open = false">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>

        <x-ui.drawer name="demo-drawer" title="Example drawer">
            <p class="text-sm text-(--content-muted)">Drawer body content goes here.</p>
        </x-ui.drawer>

        <x-ui.confirm-dialog name="demo-confirm" title="Delete record?" message="This cannot be undone." confirm-label="Delete" />
    </x-ui.card>

    <x-ui.card title="Empty state">
        <x-ui.empty-state title="No projects yet" description="Projects will be added in a later milestone.">
            <x-slot:action>
                <x-ui.button size="sm" disabled>New project</x-ui.button>
            </x-slot:action>
        </x-ui.empty-state>
    </x-ui.card>
</x-layouts.app>
