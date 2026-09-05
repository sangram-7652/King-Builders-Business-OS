@php use Illuminate\Support\Carbon; @endphp

<div class="space-y-6">
    <x-ui.page-header title="Follow-ups" description="Your follow-up work queue — today, overdue, upcoming and missed." />

    {{-- Tabs --}}
    <div class="flex flex-wrap gap-1 border-b border-(--border) pb-px">
        @foreach (['today' => 'Today', 'overdue' => 'Overdue', 'upcoming' => 'Upcoming', 'missed' => 'Missed', 'all' => 'All'] as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')"
                @class([
                    'rounded-t-lg px-3 py-2 text-sm font-medium transition',
                    'border-b-2 border-(--brand-primary) text-(--content)' => $tab === $key,
                    'text-(--content-muted) hover:text-(--content)' => $tab !== $key,
                ])>
                {{ $label }}
                @if ($key !== 'all' && ($counts[$key] ?? 0) > 0)
                    <span @class([
                        'ml-1 rounded-full px-1.5 text-xs',
                        'bg-red-500/10 text-red-600' => in_array($key, ['overdue', 'missed'], true),
                        'bg-(--brand-primary)/10 text-(--brand-primary)' => ! in_array($key, ['overdue', 'missed'], true),
                    ])>{{ $counts[$key] }}</span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-end gap-3">
        <x-ui.field label="Type" class="w-40">
            <select wire:model.live="type" class="block w-full rounded-lg border border-(--border) bg-(--surface) px-3 py-2 text-sm">
                <option value="">All types</option>
                @foreach ($types as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
            </select>
        </x-ui.field>
        <x-ui.field label="Priority" class="w-40">
            <select wire:model.live="priority" class="block w-full rounded-lg border border-(--border) bg-(--surface) px-3 py-2 text-sm">
                <option value="">Any priority</option>
                @foreach ($priorities as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
            </select>
        </x-ui.field>
        @if ($canViewAll)
            <x-ui.field label="Assignee" class="w-48">
                <select wire:model.live="assignee" class="block w-full rounded-lg border border-(--border) bg-(--surface) px-3 py-2 text-sm">
                    <option value="">Everyone</option>
                    <option value="unassigned">Unassigned</option>
                    @foreach ($assignees as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </x-ui.field>
        @endif
    </div>

    <x-ui.card>
        @if ($followUps->isEmpty())
            <x-ui.empty-state title="Nothing here" description="No follow-ups match this view." icon="clock" />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr>
                            <th class="py-2 pr-4">Lead</th>
                            <th class="py-2 pr-4">Follow-up</th>
                            <th class="py-2 pr-4">Due</th>
                            <th class="py-2 pr-4">Priority</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2 pr-4">Owner</th>
                            <th class="py-2 pr-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($followUps as $f)
                            <tr wire:key="fu-{{ $f->id }}" class="align-top hover:bg-(--surface-muted)/50">
                                <td class="py-2 pr-4">
                                    @can('view', $f->lead)
                                        <a href="{{ route('leads.show', $f->lead) }}" wire:navigate class="font-medium text-(--brand-primary) hover:underline">{{ $f->lead->name }}</a>
                                    @else <span class="font-medium">{{ $f->lead->name }}</span> @endcan
                                    <div class="text-xs text-(--content-muted)">{{ $f->lead->phone }} · {{ $f->lead->status->label() }}</div>
                                </td>
                                <td class="py-2 pr-4">
                                    <span class="font-medium">{{ $f->type->label() }}</span>
                                    @if ($f->title)<div class="text-xs text-(--content-muted)">{{ $f->title }}</div>@endif
                                    @if ($f->note)<div class="text-xs text-(--content-muted)">{{ \Illuminate\Support\Str::limit($f->note, 60) }}</div>@endif
                                </td>
                                <td class="py-2 pr-4 tabular-nums {{ $f->isOverdue() ? 'text-red-600 font-medium' : '' }}">
                                    {{ $f->due_at->format('d M Y H:i') }}
                                    <div class="text-xs text-(--content-muted)">{{ $f->due_at->diffForHumans() }}</div>
                                </td>
                                <td class="py-2 pr-4"><x-ui.badge :variant="$f->priority->color()">{{ $f->priority->label() }}</x-ui.badge></td>
                                <td class="py-2 pr-4"><x-ui.badge :variant="$f->status->color()">{{ $f->status->label() }}</x-ui.badge></td>
                                <td class="py-2 pr-4">{{ $f->assignee?->name ?? '—' }}</td>
                                <td class="py-2 pr-4 text-right">
                                    @if ($f->isOpen())
                                        <div class="inline-flex gap-1">
                                            @can('complete', $f)
                                                <x-ui.button size="sm" wire:click="startComplete({{ $f->id }})">Complete</x-ui.button>
                                            @endcan
                                            @can('reschedule', $f)
                                                <x-ui.button size="sm" variant="secondary" wire:click="startReschedule({{ $f->id }})">Reschedule</x-ui.button>
                                                <x-ui.button size="sm" variant="ghost" wire:click="cancel({{ $f->id }})"
                                                    wire:confirm="Cancel this follow-up?">Cancel</x-ui.button>
                                            @endcan
                                        </div>
                                    @elseif ($f->status === \App\Enums\FollowUpStatus::Missed)
                                        @can('reschedule', $f)
                                            <x-ui.button size="sm" variant="secondary" wire:click="startReschedule({{ $f->id }})">Reschedule</x-ui.button>
                                        @endcan
                                    @endif
                                </td>
                            </tr>

                            @if ($completingId === $f->id)
                                <tr wire:key="fu-complete-{{ $f->id }}"><td colspan="7" class="bg-(--surface-muted)/40 p-4">
                                    <form wire:submit="complete" class="flex flex-wrap items-end gap-3">
                                        <x-ui.field label="Outcome" class="w-48">
                                            <select wire:model="completeOutcome" class="block w-full rounded-lg border border-(--border) bg-(--surface) px-3 py-2 text-sm">
                                                <option value="">— optional —</option>
                                                @foreach ($outcomes as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                                            </select>
                                        </x-ui.field>
                                        <x-ui.field label="Note" class="flex-1 min-w-64">
                                            <input type="text" wire:model="completeNote" class="block w-full rounded-lg border border-(--border) bg-(--surface) px-3 py-2 text-sm" />
                                        </x-ui.field>
                                        <x-ui.button type="submit" size="sm">Save</x-ui.button>
                                        <x-ui.button type="button" size="sm" variant="ghost" wire:click="$set('completingId', null)">Cancel</x-ui.button>
                                    </form>
                                </td></tr>
                            @endif

                            @if ($reschedulingId === $f->id)
                                <tr wire:key="fu-resch-{{ $f->id }}"><td colspan="7" class="bg-(--surface-muted)/40 p-4">
                                    <form wire:submit="reschedule" class="flex flex-wrap items-end gap-3">
                                        <x-ui.field label="New due date/time" class="w-64">
                                            <input type="datetime-local" wire:model="rescheduleDueAt" class="block w-full rounded-lg border border-(--border) bg-(--surface) px-3 py-2 text-sm" />
                                            @error('rescheduleDueAt') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                                        </x-ui.field>
                                        <x-ui.button type="submit" size="sm">Reschedule</x-ui.button>
                                        <x-ui.button type="button" size="sm" variant="ghost" wire:click="$set('reschedulingId', null)">Cancel</x-ui.button>
                                    </form>
                                </td></tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($followUps->hasPages())
                <div class="mt-3">{{ $followUps->onEachSide(1)->links() }}</div>
            @endif
        @endif
    </x-ui.card>
</div>
