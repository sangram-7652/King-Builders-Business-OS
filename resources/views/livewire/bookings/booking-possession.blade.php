@php
    use App\Enums\PossessionCaseStatus;
    use App\Enums\ClearanceStatus;
@endphp
<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Bookings', 'url' => route('bookings.index')],
        ['label' => $booking->booking_number, 'url' => route('bookings.show', $booking)],
        ['label' => 'Possession'],
    ]" />

    <x-ui.page-header :title="'Possession — '.$booking->booking_number"
        description="Possession eligibility, clearances, site inspection and customer handover.">
        <x-slot:actions>
            @can('transfer.view')
                <x-ui.button variant="secondary" size="sm" :href="route('transfers.booking', $booking)" wire:navigate>Transfers</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Eligibility --}}
    <x-ui.card title="Eligibility">
        <x-slot:actions>
            @if ($case)
                <x-ui.badge :variant="$case->status->color()">{{ $case->status->label() }}</x-ui.badge>
            @elseif ($eligibility->eligible)
                <x-ui.badge variant="success">Eligible</x-ui.badge>
            @else
                <x-ui.badge variant="warning">Not eligible</x-ui.badge>
            @endif
        </x-slot:actions>

        <ul class="space-y-1 text-sm">
            @foreach ($eligibility->checks as $check)
                <li class="flex items-start gap-2">
                    <span class="{{ $check['passed'] ? 'text-emerald-600' : 'text-red-600' }}">{{ $check['passed'] ? '✔' : '✘' }}</span>
                    <span>{{ $check['label'] }}@if ($check['detail'])<span class="text-(--content-muted)"> — {{ $check['detail'] }}</span>@endif</span>
                </li>
            @endforeach
        </ul>

        <div class="mt-4 flex flex-wrap gap-2">
            @if (! $case)
                @can('create', App\Models\PossessionCase::class)
                    <x-ui.button size="sm" wire:click="initiate">Open possession case</x-ui.button>
                @endcan
            @else
                @can('update', $case)
                    <x-ui.button size="sm" variant="secondary" wire:click="refreshEligibility">Refresh eligibility</x-ui.button>
                    @if ($case->status === PossessionCaseStatus::OnHold)
                        <x-ui.button size="sm" wire:click="resume">Resume</x-ui.button>
                    @elseif ($case->status->isActive())
                        <x-ui.button size="sm" variant="ghost" wire:click="$toggle('showHold')">Put on hold</x-ui.button>
                        <x-ui.button size="sm" variant="ghost" class="text-red-600" wire:click="cancelCase" wire:confirm="Cancel this possession case?">Cancel</x-ui.button>
                    @endif
                @endcan
            @endif
        </div>

        @if ($showHold)
            <form wire:submit="putOnHold" class="mt-3 space-y-2 rounded-lg border border-(--border) p-3">
                <x-ui.input label="Hold reason" wire:model="holdReason" :error="$errors->first('holdReason')" />
                <x-ui.button size="sm" type="submit">Hold</x-ui.button>
            </form>
        @endif
    </x-ui.card>

    @if ($case)
        {{-- Clearances --}}
        <x-ui.card title="Clearances">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr><th class="py-2 pr-4">Category</th><th class="py-2 pr-4">Status</th><th class="py-2 pr-4">Decided</th><th class="py-2 pr-4 text-right">Action</th></tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($case->clearances->sortBy('category') as $c)
                            <tr wire:key="clr-{{ $c->id }}">
                                <td class="py-2 pr-4 font-medium">{{ $c->category->label() }}</td>
                                <td class="py-2 pr-4"><x-ui.badge :variant="$c->status->color()">{{ $c->status->label() }}</x-ui.badge></td>
                                <td class="py-2 pr-4 text-(--content-muted)">{{ $c->decided_at?->format('d M Y') ?? '—' }}</td>
                                <td class="py-2 pr-4">
                                    @can('clear', $case)
                                        <div class="flex flex-wrap justify-end gap-1">
                                            <x-ui.button size="sm" variant="ghost" wire:click="recordClearance('{{ $c->category->value }}', '{{ ClearanceStatus::Cleared->value }}')">Clear</x-ui.button>
                                            <x-ui.button size="sm" variant="ghost" class="text-red-600" wire:click="recordClearance('{{ $c->category->value }}', '{{ ClearanceStatus::Rejected->value }}')">Reject</x-ui.button>
                                            <x-ui.button size="sm" variant="ghost" wire:click="$set('clearingCategory', '{{ $c->category->value }}')">Waive…</x-ui.button>
                                        </div>
                                    @endcan
                                </td>
                            </tr>
                            @if ($clearingCategory === $c->category->value)
                                <tr><td colspan="4" class="py-2">
                                    <form wire:submit="recordClearance('{{ $c->category->value }}', '{{ ClearanceStatus::Waived->value }}')" class="space-y-2 rounded-lg border border-(--border) p-3">
                                        <x-ui.input label="Waiver reason (required)" wire:model="waiverReason" />
                                        <div class="flex gap-2">
                                            <x-ui.button size="sm" type="submit" variant="danger">Waive {{ $c->category->label() }}</x-ui.button>
                                            <x-ui.button size="sm" type="button" variant="secondary" wire:click="$set('clearingCategory', null)">Cancel</x-ui.button>
                                        </div>
                                    </form>
                                </td></tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        {{-- Checklist --}}
        <x-ui.card title="Possession checklist">
            <x-slot:actions>
                <x-ui.badge :variant="$checklist->isComplete() ? 'success' : 'warning'">
                    {{ $checklist->doneCount() }}/{{ $checklist->requiredCount() }} done
                </x-ui.badge>
            </x-slot:actions>
            <ul class="space-y-1 text-sm">
                @foreach ($checklist->items as $item)
                    <li class="flex items-start gap-2">
                        <span class="{{ $item['done'] ? 'text-emerald-600' : ($item['required'] ? 'text-red-600' : 'text-(--content-muted)') }}">{{ $item['done'] ? '✔' : '·' }}</span>
                        <span>{{ $item['label'] }}
                            @unless ($item['required'])<span class="text-xs text-(--content-muted)">(optional)</span>@endunless
                            @if ($item['detail'])<span class="text-(--content-muted)"> — {{ $item['detail'] }}</span>@endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        {{-- Appointment + inspection --}}
        <x-ui.card title="Appointment & inspection">
            <dl class="grid gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-(--content-muted)">Scheduled</dt><dd>{{ $case->liveAppointment?->scheduled_at?->format('d M Y H:i') ?? '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Site</dt><dd>{{ $case->liveAppointment?->site_location ?? '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Latest inspection</dt><dd>{{ $case->inspections->first()?->status->label() ?? '—' }}</dd></div>
            </dl>

            <div class="mt-3 flex flex-wrap gap-2">
                @can('schedule', $case)
                    @if ($case->status === PossessionCaseStatus::Ready)
                        <x-ui.button size="sm" wire:click="$set('showSchedule', true)">Schedule</x-ui.button>
                    @elseif (in_array($case->status, [PossessionCaseStatus::Scheduled, PossessionCaseStatus::Inspection], true))
                        <x-ui.button size="sm" variant="secondary" wire:click="$set('showSchedule', true)">Reschedule</x-ui.button>
                    @endif
                @endcan
                @can('inspect', $case)
                    @if (in_array($case->status, [PossessionCaseStatus::Scheduled, PossessionCaseStatus::Inspection, PossessionCaseStatus::ReadyForHandover], true))
                        <x-ui.button size="sm" variant="secondary" wire:click="$toggle('showInspection')">Record inspection</x-ui.button>
                    @endif
                @endcan
            </div>

            @if ($showSchedule)
                <form wire:submit="schedule({{ in_array($case->status, [PossessionCaseStatus::Scheduled, PossessionCaseStatus::Inspection], true) ? 'true' : 'false' }})" class="mt-3 space-y-2 rounded-lg border border-(--border) p-3">
                    <x-ui.input type="datetime-local" label="Scheduled at" wire:model="scheduledAt" :error="$errors->first('scheduledAt')" />
                    <x-ui.input label="Site / location" wire:model="siteLocation" :error="$errors->first('siteLocation')" />
                    <x-ui.textarea label="Notes" wire:model="scheduleNotes" rows="2" />
                    <x-ui.input label="Reschedule reason (if rescheduling)" wire:model="scheduleReason" />
                    <div class="flex gap-2">
                        <x-ui.button size="sm" type="submit">Save</x-ui.button>
                        <x-ui.button size="sm" type="button" variant="secondary" wire:click="$set('showSchedule', false)">Cancel</x-ui.button>
                    </div>
                </form>
            @endif

            @if ($showInspection)
                <form wire:submit="recordInspection" class="mt-3 space-y-2 rounded-lg border border-(--border) p-3">
                    <x-ui.select label="Outcome" wire:model="inspectionStatus" :options="$inspectionStatuses" />
                    <x-ui.input type="date" label="Inspection date" wire:model="inspectionDate" :error="$errors->first('inspectionDate')" />
                    <x-ui.textarea label="Remarks" wire:model="inspectionRemarks" rows="2" />
                    <div>
                        <label class="text-sm">Report (optional)</label>
                        <input type="file" wire:model="inspectionReport" class="mt-1 block text-sm" />
                        @error('inspectionReport') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex gap-2">
                        <x-ui.button size="sm" type="submit">Record</x-ui.button>
                        <x-ui.button size="sm" type="button" variant="secondary" wire:click="$set('showInspection', false)">Cancel</x-ui.button>
                    </div>
                </form>
            @endif
        </x-ui.card>

        {{-- Handover --}}
        <x-ui.card title="Customer handover">
            <x-slot:actions>
                @if ($case->handover)
                    <x-ui.badge :variant="$case->handover->status->color()">{{ $case->handover->status->label() }}</x-ui.badge>
                @endif
            </x-slot:actions>

            @if (! $case->handover)
                <x-ui.empty-state icon="inbox" title="Not ready yet" description="Complete the checklist, then mark ready for handover." />
                @can('complete', $case)
                    @if (in_array($case->status, [PossessionCaseStatus::Scheduled, PossessionCaseStatus::Inspection], true))
                        <div class="mt-3"><x-ui.button size="sm" wire:click="markReadyForHandover">Mark ready for handover</x-ui.button></div>
                    @endif
                @endcan
            @else
                <dl class="grid gap-3 text-sm sm:grid-cols-3">
                    <div><dt class="text-(--content-muted)">Handover date</dt><dd>{{ $case->handover->handover_date?->format('d M Y') ?? '—' }}</dd></div>
                    <div><dt class="text-(--content-muted)">Received by</dt><dd>{{ $case->handover->received_by ?: '—' }}</dd></div>
                    <div><dt class="text-(--content-muted)">Completed</dt><dd>{{ $case->handover->completed_at?->format('d M Y H:i') ?? '—' }}</dd></div>
                </dl>

                @can('complete', $case)
                    <div class="mt-3 flex flex-wrap gap-2">
                        @if ($case->handover->status === App\Enums\PossessionHandoverStatus::ReadyForHandover)
                            <x-ui.button size="sm" wire:click="$toggle('showHandover')">Record handover</x-ui.button>
                        @elseif ($case->handover->status === App\Enums\PossessionHandoverStatus::Acknowledgement)
                            <x-ui.button size="sm" wire:click="completeHandover">Complete possession</x-ui.button>
                        @endif
                        @if ($case->status === PossessionCaseStatus::Completed)
                            <x-ui.button size="sm" variant="secondary" wire:click="generateCertificate">
                                {{ $case->certificate_document_id ? 'Regenerate certificate' : 'Generate certificate' }}
                            </x-ui.button>
                        @endif
                    </div>
                @endcan

                @if ($showHandover)
                    <form wire:submit="startHandover" class="mt-3 space-y-2 rounded-lg border border-(--border) p-3">
                        <x-ui.input type="date" label="Handover date" wire:model="handoverDate" :error="$errors->first('handoverDate')" />
                        <x-ui.input label="Received by (person)" wire:model="handoverReceivedBy" :error="$errors->first('handoverReceivedBy')" />
                        <x-ui.input label="Receiver identity reference" wire:model="handoverReceiverIdentity" />
                        <x-ui.input label="Relation (self / spouse / attorney …)" wire:model="handoverReceiverRelation" />
                        <x-ui.textarea label="Remarks" wire:model="handoverRemarks" rows="2" />
                        <div>
                            <label class="text-sm">Acknowledgement (optional)</label>
                            <input type="file" wire:model="handoverAck" class="mt-1 block text-sm" />
                            @error('handoverAck') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex gap-2">
                            <x-ui.button size="sm" type="submit">Record handover</x-ui.button>
                            <x-ui.button size="sm" type="button" variant="secondary" wire:click="$set('showHandover', false)">Cancel</x-ui.button>
                        </div>
                    </form>
                @endif

                @if ($case->certificateDocument && $case->certificateDocument->versions->isNotEmpty())
                    @php $v = $case->certificateDocument->versions->sortByDesc('version')->first(); @endphp
                    <p class="mt-3 text-sm">Certificate:
                        @can('download', $case->certificateDocument)
                            <a href="{{ route('documents.download', ['document' => $case->certificateDocument->id, 'version' => $v->id]) }}" target="_blank" class="text-(--brand-primary) hover:underline">download v{{ $v->version }}</a>
                        @else v{{ $v->version }} @endcan
                    </p>
                @endif
            @endif
        </x-ui.card>
    @endif
</div>
