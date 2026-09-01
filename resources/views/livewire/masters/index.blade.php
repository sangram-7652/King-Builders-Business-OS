@php
    use App\Enums\Permission;
    $tableFields = collect($config->tableFields())->reject(fn ($f) => $f->key === 'is_active')->values();
@endphp

<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Master Data', 'url' => route('masters.dashboard')],
        ['label' => $config->pluralLabel()],
    ]" />

    <x-ui.page-header :title="$config->pluralLabel()" description="Master / configuration data used by the business modules.">
        <x-slot:actions>
            @can('create', $config->model())
                <x-ui.button :href="route('masters.create', ['resource' => $config->slug()])" wire:navigate>
                    New {{ strtolower($config->singularLabel()) }}
                </x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :padding="false">
        <div class="flex flex-col gap-3 border-b border-(--border) p-4 sm:flex-row sm:flex-wrap sm:items-end">
            <div class="flex-1 min-w-48">
                <x-ui.input label="Search" wire:model.live.debounce.300ms="search" placeholder="Search…" />
            </div>
            <div class="w-full sm:w-40">
                <x-ui.select label="Status" wire:model.live="status" placeholder="All"
                    :options="['active' => 'Active', 'inactive' => 'Inactive']" />
            </div>
            @foreach ($config->filters() as $filter)
                <div class="w-full sm:w-48" wire:key="filter-{{ $filter['key'] }}">
                    <x-ui.select :label="$filter['label']" wire:model.live="filter.{{ $filter['key'] }}" placeholder="All"
                        :options="$filter['options']" />
                </div>
            @endforeach
            @if ($search !== '' || $status !== '' || collect($filter)->filter()->isNotEmpty())
                <x-ui.button variant="ghost" wire:click="clearFilters">Clear</x-ui.button>
            @endif
        </div>

        @if ($rows->isEmpty())
            <div class="p-6">
                <x-ui.empty-state
                    :title="'No '.strtolower($config->pluralLabel()).' found'"
                    description="Adjust your filters, or add the first one." />
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="bg-(--surface-muted) text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr>
                            @foreach ($tableFields as $field)
                                <th class="px-4 py-3">{{ $field->getTableLabel() }}</th>
                            @endforeach
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($rows as $row)
                            <tr wire:key="row-{{ $row->id }}" class="hover:bg-(--surface-muted)/50">
                                @foreach ($tableFields as $i => $field)
                                    <td @class(['px-4 py-3', 'font-medium text-(--content)' => $i === 0, 'text-(--content-muted)' => $i !== 0])>
                                        {{ $field->renderCell($row) }}
                                    </td>
                                @endforeach
                                <td class="px-4 py-3">
                                    <x-ui.badge :variant="$row->is_active ? 'success' : 'muted'">
                                        {{ $row->is_active ? 'Active' : 'Inactive' }}
                                    </x-ui.badge>
                                    @if ($row->isSystem())
                                        <x-ui.badge variant="info" size="sm" class="ml-1">System</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        @can('update', $row)
                                            <x-ui.button variant="ghost" size="sm"
                                                :href="route('masters.edit', ['resource' => $config->slug(), 'record' => $row->id])"
                                                wire:navigate>Edit</x-ui.button>
                                            <x-ui.button variant="ghost" size="sm"
                                                wire:click="toggleStatus({{ $row->id }})"
                                                wire:confirm="{{ $row->is_active ? 'Deactivate' : 'Activate' }} this record?">
                                                {{ $row->is_active ? 'Deactivate' : 'Activate' }}
                                            </x-ui.button>
                                        @endcan
                                        @can('delete', $row)
                                            <x-ui.button variant="ghost" size="sm" class="text-red-600"
                                                wire:click="delete({{ $row->id }})"
                                                wire:confirm="Delete this record? This cannot be undone.">Delete</x-ui.button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-3">{{ $rows->onEachSide(1)->links() }}</div>
        @endif
    </x-ui.card>
</div>
