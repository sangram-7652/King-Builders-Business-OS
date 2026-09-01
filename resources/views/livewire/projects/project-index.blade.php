@php use App\Enums\ProjectStatus; @endphp

<div class="space-y-6">
    <x-ui.page-header title="Projects" description="Sites and townships. Blocks are managed inside each project; plot inventory arrives in M4.">
        <x-slot:actions>
            @can('create', App\Models\Project::class)
                <x-ui.button :href="route('projects.create')" wire:navigate>
                    <x-app.icon name="plus" class="size-4" /> New project
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :padding="false">
        {{-- Filters --}}
        <div class="flex flex-col gap-3 border-b border-(--border) p-4 sm:flex-row sm:flex-wrap sm:items-end">
            <div class="flex-1 min-w-48">
                <x-ui.input label="Search" wire:model.live.debounce.300ms="search" placeholder="Name, code, address…" />
            </div>
            <div class="w-full sm:w-40">
                <x-ui.select label="Status" wire:model.live="status" placeholder="All statuses" :options="$statuses" />
            </div>
            <div class="w-full sm:w-44">
                <x-ui.select label="State" wire:model.live="state" placeholder="All states" :options="$states->toArray()" />
            </div>
            <div class="w-full sm:w-44">
                <x-ui.select label="City" wire:model.live="city" placeholder="All cities"
                    :options="$cities->toArray()" :disabled="$state === ''" />
            </div>
            <div class="w-full sm:w-36">
                <x-ui.select label="Visibility" wire:model.live="active" placeholder="All"
                    :options="['active' => 'Active', 'inactive' => 'Archived']" />
            </div>
            @if ($search !== '' || $status !== '' || $state !== '' || $city !== '' || $active !== '')
                <x-ui.button variant="ghost" wire:click="clearFilters">Clear</x-ui.button>
            @endif
        </div>

        {{-- Loading bar --}}
        <div wire:loading.delay class="h-0.5 w-full animate-pulse bg-(--brand-primary)"></div>

        @if ($projects->isEmpty())
            <div class="p-6">
                <x-ui.empty-state
                    icon="building"
                    title="No projects found"
                    :description="$search !== '' || $status !== '' ? 'Try adjusting your filters.' : 'Create your first project to get started.'">
                    @can('create', App\Models\Project::class)
                        <x-slot:action>
                            <x-ui.button size="sm" :href="route('projects.create')" wire:navigate>New project</x-ui.button>
                        </x-slot:action>
                    @endcan
                </x-ui.empty-state>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="bg-(--surface-muted) text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr>
                            @foreach (['name' => 'Name', 'code' => 'Code', 'location' => 'Location', 'status' => 'Status', 'created_at' => 'Created'] as $col => $label)
                                <th class="px-4 py-3">
                                    @if (in_array($col, ['name', 'code', 'status', 'created_at'], true))
                                        <button wire:click="sortBy('{{ $col }}')" class="inline-flex items-center gap-1 hover:text-(--content)">
                                            {{ $label }}
                                            @if ($sort === $col)
                                                <span class="text-(--brand-primary)">{{ $direction === 'asc' ? '↑' : '↓' }}</span>
                                            @endif
                                        </button>
                                    @else
                                        {{ $label }}
                                    @endif
                                </th>
                            @endforeach
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($projects as $project)
                            <tr wire:key="project-{{ $project->id }}" class="hover:bg-(--surface-muted)/50">
                                <td class="px-4 py-3">
                                    <a href="{{ route('projects.show', $project) }}" wire:navigate class="font-medium text-(--content) hover:text-(--brand-primary)">
                                        {{ $project->name }}
                                    </a>
                                    <div class="mt-0.5 flex items-center gap-1.5 text-xs text-(--content-muted)">
                                        <span>{{ $project->blocks_count }} {{ Str::plural('block', $project->blocks_count) }}</span>
                                        @unless ($project->is_active)
                                            <x-ui.badge variant="muted" size="sm">Archived</x-ui.badge>
                                        @endunless
                                    </div>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-(--content-muted)">{{ $project->code }}</td>
                                <td class="px-4 py-3 text-(--content-muted)">{{ $project->locationLabel() }}</td>
                                <td class="px-4 py-3">
                                    <x-ui.badge :variant="$project->status->color()">{{ $project->status->label() }}</x-ui.badge>
                                </td>
                                <td class="px-4 py-3 text-(--content-muted)">{{ $project->created_at->format('d M Y') }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <x-ui.button variant="ghost" size="sm" :href="route('projects.show', $project)" wire:navigate>View</x-ui.button>
                                        @can('update', $project)
                                            <x-ui.button variant="ghost" size="sm" :href="route('projects.edit', $project)" wire:navigate>Edit</x-ui.button>
                                        @endcan
                                        @can($project->is_active ? 'archive' : 'activate', $project)
                                            <x-ui.button variant="ghost" size="sm"
                                                wire:click="toggleActive({{ $project->id }})"
                                                wire:confirm="{{ $project->is_active ? 'Archive' : 'Activate' }} {{ $project->name }}?">
                                                {{ $project->is_active ? 'Archive' : 'Activate' }}
                                            </x-ui.button>
                                        @endcan
                                        @can('delete', $project)
                                            <x-ui.button variant="ghost" size="sm" class="text-red-600"
                                                wire:click="delete({{ $project->id }})"
                                                wire:confirm="Delete {{ $project->name }}? This can be restored by an administrator.">
                                                Delete
                                            </x-ui.button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $projects->onEachSide(1)->links() }}</div>
        @endif
    </x-ui.card>
</div>
