<div class="space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="w-full sm:max-w-xs">
            <x-ui.input wire:model.live.debounce.300ms="search" placeholder="Search blocks…" />
        </div>
        @if ($this->canManage)
            <x-ui.button size="sm" wire:click="openCreate">
                <x-app.icon name="plus" class="size-4" /> Add block
            </x-ui.button>
        @endif
    </div>

    @if ($this->blocks->isEmpty())
        <x-ui.empty-state
            icon="layers"
            title="No blocks yet"
            :description="$search !== '' ? 'No blocks match your search.' : 'Add the first block (e.g. Block A) to start structuring this project.'">
            @if ($this->canManage && $search === '')
                <x-slot:action>
                    <x-ui.button size="sm" wire:click="openCreate">Add block</x-ui.button>
                </x-slot:action>
            @endif
        </x-ui.empty-state>
    @else
        <x-ui.card :padding="false">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="bg-(--surface-muted) text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr>
                            <th class="px-4 py-3">Order</th>
                            <th class="px-4 py-3">Name</th>
                            <th class="px-4 py-3">Code</th>
                            <th class="px-4 py-3">Description</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($this->blocks as $block)
                            <tr wire:key="block-{{ $block->id }}" class="hover:bg-(--surface-muted)/50">
                                <td class="px-4 py-3 text-(--content-muted)">{{ $block->sort_order }}</td>
                                <td class="px-4 py-3 font-medium text-(--content)">{{ $block->name }}</td>
                                <td class="px-4 py-3 font-mono text-xs text-(--content-muted)">{{ $block->code }}</td>
                                <td class="px-4 py-3 text-(--content-muted)">{{ $block->description ?: '—' }}</td>
                                <td class="px-4 py-3">
                                    <x-ui.badge :variant="$block->is_active ? 'success' : 'muted'">
                                        {{ $block->is_active ? 'Active' : 'Inactive' }}
                                    </x-ui.badge>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        @can('viewAny', App\Models\Plot::class)
                                            <x-ui.button variant="ghost" size="sm"
                                                :href="route('plots.index', ['project' => $project->id, 'block' => $block->id])"
                                                wire:navigate>Plots</x-ui.button>
                                        @endcan
                                        @if ($this->canManage)
                                            <x-ui.button variant="ghost" size="sm" wire:click="openEdit({{ $block->id }})">Edit</x-ui.button>
                                            <x-ui.button variant="ghost" size="sm"
                                                wire:click="toggleBlock({{ $block->id }})"
                                                wire:confirm="{{ $block->is_active ? 'Deactivate' : 'Activate' }} {{ $block->name }}?">
                                                {{ $block->is_active ? 'Deactivate' : 'Activate' }}
                                            </x-ui.button>
                                            <x-ui.button variant="ghost" size="sm" class="text-red-600"
                                                wire:click="deleteBlock({{ $block->id }})"
                                                wire:confirm="Delete {{ $block->name }}?">Delete</x-ui.button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif

    {{-- Add / edit drawer --}}
    @if ($this->canManage)
    <div
        x-data="{ open: @entangle('showForm') }"
        x-show="open"
        x-on:keydown.escape.window="$wire.closeForm()"
        style="display:none"
        class="fixed inset-0 z-50"
        role="dialog" aria-modal="true">
        <div x-show="open" x-transition.opacity wire:click="closeForm" class="absolute inset-0 bg-slate-900/50"></div>

        <div x-show="open"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
             class="absolute inset-y-0 right-0 flex w-full flex-col border-l border-(--border) bg-(--surface) shadow-xl sm:max-w-md">
            <div class="flex items-center justify-between border-b border-(--border) px-5 py-4">
                <h3 class="text-sm font-semibold">{{ $editingId ? 'Edit block' : 'Add block' }}</h3>
                <button type="button" wire:click="closeForm" class="rounded-lg p-1 text-(--content-muted) hover:bg-(--surface-muted)">
                    <x-app.icon name="x" class="size-4" />
                </button>
            </div>

            <form wire:submit="saveBlock" class="flex flex-1 flex-col">
                <div class="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                    <x-ui.input label="Name" wire:model="name" required :error="$errors->first('name')" placeholder="Block A" />
                    <x-ui.input label="Code" wire:model="code" required :error="$errors->first('code')"
                        hint="Unique within this project" placeholder="A" />
                    <x-ui.textarea label="Description" wire:model="description" :error="$errors->first('description')" rows="2" />
                    <x-ui.input type="number" label="Sort order" wire:model="sort_order" :error="$errors->first('sort_order')" />
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="is_active" class="rounded border-(--border)"> Active
                    </label>
                </div>
                <div class="flex justify-end gap-2 border-t border-(--border) px-5 py-3">
                    <x-ui.button type="button" variant="secondary" wire:click="closeForm">Cancel</x-ui.button>
                    <x-ui.button type="submit">{{ $editingId ? 'Save block' : 'Add block' }}</x-ui.button>
                </div>
            </form>
        </div>
    </div>
    @endif
</div>
