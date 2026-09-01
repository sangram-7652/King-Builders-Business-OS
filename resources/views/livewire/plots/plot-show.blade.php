@php use App\Enums\PlotStatus; @endphp

<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Projects', 'url' => route('projects.index')],
        ['label' => $project->name, 'url' => route('projects.show', $project)],
        ['label' => $block->name, 'url' => route('plots.index', ['project' => $project->id, 'block' => $block->id])],
        ['label' => 'Plot '.$plot->plot_number],
    ]" />

    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold tracking-tight text-(--content)">Plot {{ $plot->plot_number }}</h1>
                <x-ui.badge :variant="$plot->status->color()">{{ $plot->status->label() }}</x-ui.badge>
                @unless ($plot->is_active)<x-ui.badge variant="muted">Archived</x-ui.badge>@endunless
            </div>
            <p class="mt-1 text-sm text-(--content-muted)">{{ $project->name }} · {{ $block->name }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($plot->status === PlotStatus::Available)
                @can('hold', $plot)
                    <x-ui.button variant="secondary" size="sm" wire:click="openHold">Place on hold</x-ui.button>
                @endcan
            @elseif ($plot->status === PlotStatus::Hold)
                @can('release', $plot)
                    <x-ui.button variant="secondary" size="sm" wire:click="release"
                        wire:confirm="Release this hold and return the plot to Available?">Release hold</x-ui.button>
                @endcan
            @endif

            @can('changeStatus', $plot)
                @if (! empty($allowedTransitions))
                    <div x-data="{ open: false }" class="relative">
                        <x-ui.button variant="secondary" size="sm" x-on:click="open = !open" @click.outside="open = false">
                            Change status <x-app.icon name="chevron-down" class="size-3.5" />
                        </x-ui.button>
                        <div x-show="open" x-transition style="display:none"
                             class="absolute right-0 z-10 mt-1 w-44 rounded-lg border border-(--border) bg-(--surface) p-1 shadow-lg">
                            @foreach ($allowedTransitions as $target)
                                @continue($target === PlotStatus::Available && $plot->status === PlotStatus::Hold)
                                <button type="button" wire:click="changeStatus('{{ $target->value }}')" x-on:click="open = false"
                                    wire:confirm="Mark this plot {{ $target->label() }}?"
                                    class="block w-full rounded-md px-3 py-1.5 text-left text-sm hover:bg-(--surface-muted)">
                                    → {{ $target->label() }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endcan

            @can($plot->is_active ? 'archive' : 'activate', $plot)
                <x-ui.button variant="secondary" size="sm" wire:click="toggleActive"
                    wire:confirm="{{ $plot->is_active ? 'Archive' : 'Activate' }} this plot?">
                    {{ $plot->is_active ? 'Archive' : 'Activate' }}
                </x-ui.button>
            @endcan

            @can('update', $plot)
                <x-ui.button size="sm" :href="route('plots.edit', ['project' => $project->id, 'block' => $block->id, 'plot' => $plot->id])" wire:navigate>Edit</x-ui.button>
            @endcan
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Details" class="lg:col-span-2">
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="text-(--content-muted)">Project</dt><dd class="mt-0.5">{{ $project->name }}</dd></div>
                <div><dt class="text-(--content-muted)">Block</dt><dd class="mt-0.5">{{ $block->name }}</dd></div>
                <div><dt class="text-(--content-muted)">Category</dt><dd class="mt-0.5">{{ $plot->category?->name ?? '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Size</dt><dd class="mt-0.5">{{ $plot->size?->name ?? '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Area</dt><dd class="mt-0.5">{{ $plot->areaLabel() }}</dd></div>
                <div><dt class="text-(--content-muted)">Dimension</dt><dd class="mt-0.5">{{ $plot->dimension?->display_name ?? '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Facing</dt><dd class="mt-0.5">{{ $plot->facing?->label() ?? '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Status</dt><dd class="mt-0.5">{{ $plot->status->label() }}</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card title="Hold">
            @if ($plot->status === PlotStatus::Hold)
                <dl class="space-y-2 text-sm">
                    <div><dt class="text-(--content-muted)">Held by</dt><dd>{{ $plot->heldBy?->name ?? '—' }}</dd></div>
                    <div><dt class="text-(--content-muted)">Since</dt><dd>{{ $plot->held_at?->format('d M Y H:i') ?? '—' }}</dd></div>
                    <div><dt class="text-(--content-muted)">Expires</dt>
                        <dd>
                            {{ $plot->hold_expires_at?->format('d M Y H:i') ?? 'Open-ended' }}
                            @if ($plot->isHoldExpired())
                                <x-ui.badge variant="danger" size="sm" class="ml-1">Lapsed</x-ui.badge>
                            @endif
                        </dd></div>
                    <div><dt class="text-(--content-muted)">Reason</dt><dd>{{ $plot->hold_reason ?: '—' }}</dd></div>
                </dl>
            @else
                <p class="text-sm text-(--content-muted)">This plot is not on hold.</p>
            @endif
        </x-ui.card>
    </div>

    {{-- Reserved sections (not implemented in M4) --}}
    <x-ui.card title="Coming later">
        <div class="grid gap-3 text-sm text-(--content-muted) sm:grid-cols-2 lg:grid-cols-3">
            @foreach (['Pricing (M6)', 'Booking (M5)', 'Payments', 'Documents', 'History'] as $section)
                <div class="rounded-lg border border-dashed border-(--border) px-3 py-2">{{ $section }}</div>
            @endforeach
        </div>
    </x-ui.card>

    {{-- Hold dialog --}}
    @if ($showHold)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" wire:key="hold-dialog">
            <div class="absolute inset-0 bg-slate-900/50" wire:click="closeHold"></div>
            <div class="relative w-full max-w-md rounded-xl border border-(--border) bg-(--surface) shadow-xl">
                <div class="border-b border-(--border) px-5 py-4"><h3 class="text-sm font-semibold">Place plot {{ $plot->plot_number }} on hold</h3></div>
                <form wire:submit="hold" class="space-y-4 px-5 py-4">
                    <x-ui.input type="datetime-local" label="Hold expires at" wire:model="holdExpiresAt"
                        :error="$errors->first('holdExpiresAt')" hint="Leave blank for an open-ended hold." />
                    <x-ui.textarea label="Reason" wire:model="holdReason" rows="2" :error="$errors->first('holdReason')" />
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="secondary" wire:click="closeHold">Cancel</x-ui.button>
                        <x-ui.button type="submit">Place on hold</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
