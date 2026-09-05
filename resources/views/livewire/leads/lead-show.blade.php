@php use App\Enums\LeadStatus; @endphp

<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Leads', 'url' => route('leads.index')],
        ['label' => $lead->name],
    ]" />

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold tracking-tight text-(--content)">{{ $lead->name }}</h1>
                <x-ui.badge :variant="$lead->status->color()">{{ $lead->status->label() }}</x-ui.badge>
            </div>
            <p class="mt-1 text-sm text-(--content-muted)">{{ $lead->phone }}@if ($lead->email) · {{ $lead->email }} @endif</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($lead->status === LeadStatus::Qualified && $canConvert)
                <x-ui.button size="sm" :href="route('leads.convert', $lead)" wire:navigate>Convert to buyer</x-ui.button>
            @endif

            @can('changeStatus', $lead)
                @if (! empty($allowedTransitions))
                    <div x-data="{ open: false }" class="relative">
                        <x-ui.button variant="secondary" size="sm" x-on:click="open = !open" @click.outside="open = false">
                            Change status <x-app.icon name="chevron-down" class="size-3.5" />
                        </x-ui.button>
                        <div x-show="open" x-transition style="display:none" class="absolute right-0 z-10 mt-1 w-44 rounded-lg border border-(--border) bg-(--surface) p-1 shadow-lg">
                            @foreach ($allowedTransitions as $t)
                                <button type="button" wire:click="changeStatus('{{ $t->value }}')" x-on:click="open = false"
                                    class="block w-full rounded-md px-3 py-1.5 text-left text-sm hover:bg-(--surface-muted)">→ {{ $t->label() }}</button>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endcan

            @can('assign', $lead)
                <x-ui.button variant="secondary" size="sm" wire:click="openAssign">Assign</x-ui.button>
            @endcan
            @can('followUp', $lead)
                <x-ui.button variant="secondary" size="sm" wire:click="openFollowUp">Schedule follow-up</x-ui.button>
            @endcan
            @can('update', $lead)
                <x-ui.button size="sm" :href="route('leads.edit', $lead)" wire:navigate>Edit</x-ui.button>
            @endcan
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Basic info --}}
        <x-ui.card title="Basic information" class="lg:col-span-2">
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div><dt class="text-(--content-muted)">Source</dt><dd class="mt-0.5">{{ $lead->source?->name ?? '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Assigned to</dt><dd class="mt-0.5">{{ $lead->assignedTo?->name ?? 'Unassigned' }}</dd></div>
                <div><dt class="text-(--content-muted)">Created by</dt><dd class="mt-0.5">{{ $lead->createdBy?->name ?? '—' }} · {{ $lead->created_at->format('d M Y') }}</dd></div>
                <div><dt class="text-(--content-muted)">Next follow-up</dt><dd class="mt-0.5">{{ $lead->follow_up_at?->format('d M Y H:i') ?? '—' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-(--content-muted)">Notes</dt><dd class="mt-0.5 whitespace-pre-line">{{ $lead->notes ?: '—' }}</dd></div>
            </dl>
        </x-ui.card>

        {{-- Conversion --}}
        <x-ui.card title="Conversion">
            @if ($lead->isConverted())
                <dl class="space-y-2 text-sm">
                    <div><dt class="text-(--content-muted)">Buyer</dt>
                        <dd><a href="{{ route('buyers.show', $lead->buyer_id) }}" wire:navigate class="font-medium text-(--brand-primary) hover:underline">{{ $lead->buyer?->customer_code }}</a></dd></div>
                    <div><dt class="text-(--content-muted)">Converted</dt><dd>{{ $lead->converted_at?->format('d M Y H:i') }}</dd></div>
                    <div><dt class="text-(--content-muted)">By</dt><dd>{{ $lead->convertedBy?->name ?? '—' }}</dd></div>
                </dl>
            @elseif ($lead->status === LeadStatus::Qualified)
                <p class="text-sm text-(--content-muted)">This lead is qualified and ready to convert.</p>
                @if ($canConvert)
                    <x-ui.button size="sm" class="mt-3" :href="route('leads.convert', $lead)" wire:navigate>Convert to buyer →</x-ui.button>
                @endif
            @else
                <p class="text-sm text-(--content-muted)">A lead must be <strong>Qualified</strong> before it can be converted.</p>
            @endif
        </x-ui.card>
    </div>

    {{-- Channel partner attribution (M14.2) --}}
    <x-ui.card title="Channel partner">
        <x-slot:actions>
            @if ($canAttributePartner)
                <x-ui.button variant="secondary" size="sm" wire:click="openAttribute">
                    {{ $lead->partner ? 'Change' : 'Attribute' }}
                </x-ui.button>
            @endif
        </x-slot:actions>

        @if ($lead->partner)
            <p class="text-sm">
                <a href="{{ route('partners.show', $lead->partner) }}" wire:navigate class="font-medium text-(--brand-primary) hover:underline">{{ $lead->partner->displayName() }}</a>
                <span class="text-(--content-muted)"> · {{ $lead->partner->partner_code }}</span>
            </p>
        @else
            <p class="text-sm text-(--content-muted)">Direct lead — no channel partner attributed.</p>
        @endif

        @if ($lead->partnerAttributions->count() > 1 || ($lead->partnerAttributions->count() === 1 && ! $lead->partnerAttributions->first()->isCurrent()))
            <ol class="mt-3 space-y-1 border-t border-(--border) pt-3 text-xs text-(--content-muted)">
                @foreach ($lead->partnerAttributions as $span)
                    <li wire:key="lpa-{{ $span->id }}">
                        {{ $span->partner?->displayName() ?? 'Direct' }}
                        · {{ $span->attributed_at->format('d M Y') }}
                        @if ($span->ended_at) → {{ $span->ended_at->format('d M Y') }} @else <span class="text-(--content)">(current)</span> @endif
                        · by {{ $span->attributedBy?->name ?? 'System' }}
                    </li>
                @endforeach
            </ol>
        @endif
    </x-ui.card>

    {{-- Follow-ups --}}
    <x-ui.card title="Follow-ups" :padding="false">
        @if ($lead->followUps->isEmpty())
            <div class="p-6"><x-ui.empty-state icon="clock" title="No follow-ups yet" /></div>
        @else
            <ul class="divide-y divide-(--border)">
                @foreach ($lead->followUps as $fu)
                    <li wire:key="fu-{{ $fu->id }}" class="flex items-start justify-between gap-4 px-5 py-3">
                        <div class="text-sm">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-(--content)">{{ $fu->type->label() }} · {{ $fu->due_at->format('d M Y H:i') }}</span>
                                <x-ui.badge :variant="$fu->status->color()" size="sm">{{ $fu->status->label() }}</x-ui.badge>
                                <x-ui.badge :variant="$fu->priority->color()" size="sm">{{ $fu->priority->label() }}</x-ui.badge>
                                @if ($fu->isCompleted() && $fu->outcome)
                                    <x-ui.badge variant="muted" size="sm">{{ $fu->outcome->label() }}</x-ui.badge>
                                @endif
                            </div>
                            @if ($fu->title)<p class="mt-0.5 font-medium text-(--content)">{{ $fu->title }}</p>@endif
                            @if ($fu->note)<p class="mt-0.5 text-(--content-muted)">{{ $fu->note }}</p>@endif
                            <p class="mt-0.5 text-xs text-(--content-muted)">{{ $fu->assignee?->name ?? $fu->createdBy?->name }}</p>
                        </div>
                        @if ($fu->isOpen())
                            @can('followUp', $lead)
                                <div class="flex shrink-0 gap-1">
                                    <x-ui.button variant="ghost" size="sm" wire:click="openComplete({{ $fu->id }})">Complete</x-ui.button>
                                    <x-ui.button variant="ghost" size="sm" wire:click="startReschedule({{ $fu->id }})">Reschedule</x-ui.button>
                                    <x-ui.button variant="ghost" size="sm" wire:click="cancelFollowUp({{ $fu->id }})" wire:confirm="Cancel this follow-up?">Cancel</x-ui.button>
                                </div>
                            @endcan
                        @endif
                    </li>

                    @if ($reschedulingId === $fu->id)
                        <li wire:key="fu-resch-{{ $fu->id }}" class="bg-(--surface-muted)/40 px-5 py-3">
                            <form wire:submit="reschedule" class="flex flex-wrap items-end gap-3">
                                <x-ui.input type="datetime-local" label="New due" wire:model="rescheduleDueAt" :error="$errors->first('rescheduleDueAt')" />
                                <x-ui.button type="submit" size="sm">Save</x-ui.button>
                                <x-ui.button type="button" size="sm" variant="ghost" wire:click="$set('reschedulingId', null)">Cancel</x-ui.button>
                            </form>
                        </li>
                    @endif
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    {{-- Activity timeline --}}
    <x-ui.card title="Activity">
        @if ($lead->activities->isEmpty())
            <x-ui.empty-state icon="clock" title="No activity" />
        @else
            <ol class="space-y-4">
                @foreach ($lead->activities as $act)
                    <li wire:key="act-{{ $act->id }}" class="flex gap-3">
                        <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-(--surface-muted) text-(--content-muted)">
                            <x-app.icon :name="$act->type->icon()" class="size-4" />
                        </span>
                        <div class="text-sm">
                            <p class="text-(--content)">{{ $act->description }}</p>
                            <p class="text-xs text-(--content-muted)">
                                {{ $act->causer?->name ?? 'System' }} · {{ $act->created_at?->diffForHumans() }}
                            </p>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-ui.card>

    {{-- Assignment history --}}
    @if ($lead->assignments->isNotEmpty())
        <x-ui.card title="Assignment history">
            <ol class="space-y-2 text-sm">
                @foreach ($lead->assignments as $span)
                    <li wire:key="span-{{ $span->id }}" class="flex flex-wrap items-baseline justify-between gap-2">
                        <span>
                            <span class="font-medium">{{ $span->assignee?->name ?? 'Unassigned' }}</span>
                            @if ($span->isCurrent())<x-ui.badge variant="success" size="sm">current</x-ui.badge>@endif
                            @if ($span->reason)<span class="text-(--content-muted)">— {{ $span->reason }}</span>@endif
                        </span>
                        <span class="text-xs text-(--content-muted)">
                            {{ $span->assigned_at->format('d M Y') }}
                            @if ($span->ended_at) → {{ $span->ended_at->format('d M Y') }} @endif
                            · by {{ $span->assignedBy?->name ?? 'System' }}
                        </span>
                    </li>
                @endforeach
            </ol>
        </x-ui.card>
    @endif

    {{-- Assign drawer --}}
    @if ($showAssign)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" wire:key="assign">
            <div class="absolute inset-0 bg-slate-900/50" wire:click="closeAssign"></div>
            <div class="relative w-full max-w-md rounded-xl border border-(--border) bg-(--surface) shadow-xl">
                <div class="border-b border-(--border) px-5 py-4"><h3 class="text-sm font-semibold">Assign lead</h3></div>
                <form wire:submit="assign" class="space-y-4 px-5 py-4">
                    <x-ui.select label="Assign to" wire:model="assignTo" placeholder="Unassigned" :options="$assignableUsers->toArray()" :error="$errors->first('assignTo')" />
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="secondary" wire:click="closeAssign">Cancel</x-ui.button>
                        <x-ui.button type="submit">Save</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Channel partner attribution drawer (M14.2) --}}
    @if ($showAttribute)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" wire:key="attribute">
            <div class="absolute inset-0 bg-slate-900/50" wire:click="closeAttribute"></div>
            <div class="relative w-full max-w-md rounded-xl border border-(--border) bg-(--surface) shadow-xl">
                <div class="border-b border-(--border) px-5 py-4"><h3 class="text-sm font-semibold">Attribute channel partner</h3></div>
                <form wire:submit="attributePartner" class="space-y-4 px-5 py-4">
                    <x-ui.select label="Channel partner" wire:model="attributePartnerId" placeholder="Direct — no partner"
                        :error="$errors->first('attributePartnerId')">
                        @foreach ($attributablePartners as $partner)
                            <option value="{{ $partner->id }}">{{ $partner->displayName() }} ({{ $partner->partner_code }})</option>
                        @endforeach
                    </x-ui.select>
                    <p class="text-xs text-(--content-muted)">Only active partners are listed. Changing this keeps the full attribution history.</p>
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="secondary" wire:click="closeAttribute">Cancel</x-ui.button>
                        <x-ui.button type="submit">Save</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Follow-up drawer --}}
    @if ($showFollowUp)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" wire:key="fu-new">
            <div class="absolute inset-0 bg-slate-900/50" wire:click="closeFollowUp"></div>
            <div class="relative w-full max-w-md rounded-xl border border-(--border) bg-(--surface) shadow-xl">
                <div class="border-b border-(--border) px-5 py-4"><h3 class="text-sm font-semibold">Schedule follow-up</h3></div>
                <form wire:submit="scheduleFollowUp" class="space-y-4 px-5 py-4">
                    <div class="grid grid-cols-2 gap-3">
                        <x-ui.select label="Type" wire:model="followUpType" :options="$followUpTypes" :placeholder="null" />
                        <x-ui.select label="Priority" wire:model="followUpPriority" :options="$followUpPriorities" :placeholder="null" />
                    </div>
                    <x-ui.input type="datetime-local" label="Due at" wire:model="followUpDueAt" required :error="$errors->first('followUpDueAt')" />
                    <x-ui.input label="Title (optional)" wire:model="followUpTitle" :error="$errors->first('followUpTitle')" />
                    <x-ui.textarea label="Note" wire:model="followUpNote" rows="3" :error="$errors->first('followUpNote')" />
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="secondary" wire:click="closeFollowUp">Cancel</x-ui.button>
                        <x-ui.button type="submit">Schedule</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Complete follow-up dialog --}}
    @if ($completingId !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" wire:key="fu-complete">
            <div class="absolute inset-0 bg-slate-900/50" wire:click="closeComplete"></div>
            <div class="relative w-full max-w-md rounded-xl border border-(--border) bg-(--surface) shadow-xl">
                <div class="border-b border-(--border) px-5 py-4"><h3 class="text-sm font-semibold">Complete follow-up</h3></div>
                <form wire:submit="completeFollowUp" class="space-y-4 px-5 py-4">
                    <x-ui.select label="Outcome (optional)" wire:model="completeOutcome" placeholder="—" :options="$outcomes" :error="$errors->first('completeOutcome')" />
                    <x-ui.textarea label="Note" wire:model="completeNote" rows="3" :error="$errors->first('completeNote')" />
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="secondary" wire:click="closeComplete">Cancel</x-ui.button>
                        <x-ui.button type="submit">Mark complete</x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
