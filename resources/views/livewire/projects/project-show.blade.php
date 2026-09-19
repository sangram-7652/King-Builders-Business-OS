@php use Illuminate\Support\Str; @endphp

<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Projects', 'url' => route('projects.index')],
        ['label' => $project->name],
    ]" />

    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold tracking-tight text-(--content)">{{ $project->name }}</h1>
                <x-ui.badge :variant="$project->status->color()">{{ $project->status->label() }}</x-ui.badge>
                @unless ($project->is_active)
                    <x-ui.badge variant="muted">Archived</x-ui.badge>
                @endunless
            </div>
            <p class="mt-1 text-sm text-(--content-muted)">
                <span class="font-mono">{{ $project->code }}</span>
                <span class="mx-1.5">·</span>
                {{ $project->locationLabel() }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            {{-- Status transitions --}}
            @can('changeStatus', $project)
                @if (! empty($allowedTransitions))
                    <div x-data="{ open: false }" class="relative">
                        <x-ui.button variant="secondary" size="sm" x-on:click="open = !open" @click.outside="open = false">
                            Change status <x-app.icon name="chevron-down" class="size-3.5" />
                        </x-ui.button>
                        <div x-show="open" x-transition style="display:none"
                             class="absolute right-0 z-10 mt-1 w-48 rounded-lg border border-(--border) bg-(--surface) p-1 shadow-lg">
                            @foreach ($allowedTransitions as $target)
                                <button type="button"
                                    wire:click="changeStatus('{{ $target->value }}')"
                                    x-on:click="open = false"
                                    wire:confirm="Move this project to {{ $target->label() }}?"
                                    class="block w-full rounded-md px-3 py-1.5 text-left text-sm hover:bg-(--surface-muted)">
                                    → {{ $target->label() }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @else
                    <x-ui.badge variant="muted" title="No further transitions">Lifecycle complete</x-ui.badge>
                @endif
            @endcan

            @can($project->is_active ? 'archive' : 'activate', $project)
                <x-ui.button variant="secondary" size="sm"
                    wire:click="toggleActive"
                    wire:confirm="{{ $project->is_active ? 'Archive' : 'Activate' }} this project?">
                    {{ $project->is_active ? 'Archive' : 'Activate' }}
                </x-ui.button>
            @endcan

            @can('update', $project)
                <x-ui.button size="sm" :href="route('projects.edit', $project)" wire:navigate>Edit</x-ui.button>
            @endcan

            @can('delete', $project)
                <x-ui.button variant="danger" size="sm"
                    wire:click="delete"
                    wire:confirm="Delete {{ $project->name }}? Blocks will be removed too. An administrator can restore it.">
                    Delete
                </x-ui.button>
            @endcan
        </div>
    </div>

    {{-- Tabs --}}
    <div class="border-b border-(--border)">
        <nav class="-mb-px flex gap-6 text-sm">
            @foreach ($tabs as $t)
                <button type="button" wire:click="setTab('{{ $t }}')"
                    @class([
                        'border-b-2 px-1 py-3 font-medium capitalize transition',
                        'border-(--brand-primary) text-(--brand-primary)' => $tab === $t,
                        'border-transparent text-(--content-muted) hover:text-(--content)' => $tab !== $t,
                    ])>
                    {{ $t }}
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Tab: Overview --}}
    @if ($tab === 'overview')
        <div class="grid gap-6 lg:grid-cols-3">
            <x-ui.card title="Summary" class="lg:col-span-2">
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-(--content-muted)">Status</dt><dd class="mt-0.5">{{ $project->status->label() }}</dd></div>
                    <div><dt class="text-(--content-muted)">Visibility</dt><dd class="mt-0.5">{{ $project->is_active ? 'Active' : 'Archived' }}</dd></div>
                    <div><dt class="text-(--content-muted)">Launch date</dt><dd class="mt-0.5">{{ $project->launch_date?->format('d M Y') ?? '—' }}</dd></div>
                    <div><dt class="text-(--content-muted)">Created</dt><dd class="mt-0.5">{{ $project->created_at->format('d M Y') }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-(--content-muted)">Description</dt>
                        <dd class="mt-0.5 whitespace-pre-line">{{ $project->description ?: '—' }}</dd></div>
                </dl>
            </x-ui.card>

            <x-ui.card title="Blocks">
                <div class="flex items-baseline gap-2">
                    <span class="text-2xl font-semibold">{{ $blockStats['active'] }}</span>
                    <span class="text-sm text-(--content-muted)">active of {{ $blockStats['total'] }}</span>
                </div>
                <x-ui.button variant="ghost" size="sm" class="mt-3" wire:click="setTab('blocks')">Manage blocks →</x-ui.button>
            </x-ui.card>
        </div>
    @endif

    {{-- Tab: Inventory --}}
    @if ($tab === 'inventory')
        @livewire('plots.project-inventory', ['project' => $project], key('inventory-'.$project->id))
    @endif

    {{-- Tab: Location --}}
    @if ($tab === 'location')
        <x-ui.card title="Location">
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="text-(--content-muted)">State</dt><dd class="mt-0.5">{{ $project->state?->name ?? '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">City</dt><dd class="mt-0.5">{{ $project->city?->name ?? '—' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-(--content-muted)">Address</dt><dd class="mt-0.5">{{ $project->address ?: '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Pincode</dt><dd class="mt-0.5">{{ $project->pincode ?: '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Coordinates</dt>
                    <dd class="mt-0.5">
                        @if ($project->latitude !== null && $project->longitude !== null)
                            {{ $project->latitude }}, {{ $project->longitude }}
                        @else — @endif
                    </dd></div>
            </dl>
        </x-ui.card>
    @endif

    {{-- Tab: Blocks --}}
    @if ($tab === 'blocks')
        @livewire('projects.project-blocks', ['project' => $project], key('blocks-'.$project->id))
    @endif

    {{-- Tab: Activity --}}
    @if ($tab === 'activity')
        <x-ui.card title="Activity">
            <x-ui.empty-state
                icon="clock"
                title="Activity timeline coming later"
                description="Domain events are already logged to the application log. A user-facing audit trail is a later milestone." />
        </x-ui.card>
    @endif
</div>
