@php use App\Enums\RegistryCaseStatus; use App\Enums\HandoverStatus; @endphp
<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Bookings', 'url' => route('bookings.index')],
        ['label' => $booking->booking_number, 'url' => route('bookings.show', $booking)],
        ['label' => 'Registry'],
    ]" />

    <x-ui.page-header :title="'Registry — '.$booking->booking_number"
        description="{{ $booking->project?->name }} · Plot {{ $booking->plot?->plot_number }}. Consumes booking / payment / collection / document state; never changes the price.">
        <x-slot:actions>
            <x-ui.button variant="secondary" size="sm" :href="route('documents.booking', $booking)" wire:navigate>Documents</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Eligibility --}}
    <x-ui.card title="Registry eligibility" subtitle="Single source of truth — App\Services\Registry\RegistryEligibilityService.">
        <div class="mb-3">
            @if ($eligibility->eligible)
                <x-ui.badge variant="success">Eligible</x-ui.badge>
            @else
                <x-ui.badge variant="danger">Not yet eligible</x-ui.badge>
            @endif
        </div>
        <ul class="space-y-1 text-sm">
            @foreach ($eligibility->checks as $check)
                <li class="flex items-start gap-2">
                    <span class="{{ $check['passed'] ? 'text-emerald-600' : 'text-red-600' }}">{{ $check['passed'] ? '✓' : '✗' }}</span>
                    <span>{{ $check['label'] }}@if ($check['detail']) <span class="text-(--content-muted)">— {{ $check['detail'] }}</span>@endif</span>
                </li>
            @endforeach
        </ul>
    </x-ui.card>

    {{-- Case --}}
    <x-ui.card title="Registry case">
        <x-slot:actions>
            @if (! $case)
                @can('create', App\Models\RegistryCase::class)
                    <x-ui.button size="sm" wire:click="initiate">Open registry case</x-ui.button>
                @endcan
            @else
                <x-ui.badge :variant="$case->status->color()">{{ $case->status->label() }}</x-ui.badge>
            @endif
        </x-slot:actions>

        @if (! $case)
            <x-ui.empty-state icon="building" title="Registry not started" description="Open the case to track eligibility, appointment and completion." />
        @else
            <dl class="grid gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-(--content-muted)">Case number</dt><dd class="mt-0.5">{{ $case->case_number }}</dd></div>
                <div><dt class="text-(--content-muted)">Initiated</dt><dd class="mt-0.5">{{ $case->initiated_at?->format('d M Y') }}</dd></div>
                <div><dt class="text-(--content-muted)">Eligibility checked</dt><dd class="mt-0.5">{{ $case->eligibility_checked_at?->format('d M Y H:i') ?? '—' }}</dd></div>
                @if ($case->scheduled_at)
                    <div><dt class="text-(--content-muted)">Appointment</dt><dd class="mt-0.5">{{ $case->scheduled_at->format('d M Y H:i') }}</dd></div>
                    <div><dt class="text-(--content-muted)">Registry office</dt><dd class="mt-0.5">{{ $case->registry_office }}</dd></div>
                    <div><dt class="text-(--content-muted)">Reference</dt><dd class="mt-0.5">{{ $case->appointment_reference ?: '—' }}</dd></div>
                @endif
                @if ($case->isCompleted())
                    <div><dt class="text-(--content-muted)">Registered document</dt><dd class="mt-0.5">{{ $case->registered_document_number }}</dd></div>
                    <div><dt class="text-(--content-muted)">Registration date</dt><dd class="mt-0.5">{{ $case->registration_date?->format('d M Y') }}</dd></div>
                @endif
                @if ($case->isOnHold())
                    <div class="sm:col-span-3"><dt class="text-(--content-muted)">On hold</dt><dd class="mt-0.5">{{ $case->hold_reason }}</dd></div>
                @endif
            </dl>

            <div class="mt-4 flex flex-wrap gap-2">
                @can('update', $case)
                    <x-ui.button size="sm" variant="secondary" wire:click="refreshEligibility">Re-check eligibility</x-ui.button>
                @endcan
                @can('schedule', $case)
                    @if ($case->status === RegistryCaseStatus::Ready)
                        <x-ui.button size="sm" wire:click="$set('showSchedule', true)">Schedule appointment</x-ui.button>
                    @elseif (in_array($case->status, [RegistryCaseStatus::Scheduled, RegistryCaseStatus::InProcess], true))
                        <x-ui.button size="sm" variant="secondary" wire:click="$set('showSchedule', true)" x-on:click="$wire.rescheduleMode = true">Reschedule</x-ui.button>
                    @endif
                @endcan
                @can('update', $case)
                    @if ($case->status === RegistryCaseStatus::Scheduled)
                        <x-ui.button size="sm" variant="secondary" wire:click="markInProcess">Mark in process</x-ui.button>
                    @endif
                @endcan
                @can('complete', $case)
                    <x-ui.button size="sm" wire:click="$set('showComplete', true)">Complete registry</x-ui.button>
                @endcan
                @can('update', $case)
                    @if ($case->isOnHold())
                        <x-ui.button size="sm" variant="secondary" wire:click="resume">Resume</x-ui.button>
                    @elseif ($case->status->isActive())
                        <x-ui.button size="sm" variant="ghost" wire:click="$set('showHold', true)">Put on hold</x-ui.button>
                        <x-ui.button size="sm" variant="ghost" class="text-red-600" wire:click="cancelCase" wire:confirm="Cancel this registry case?">Cancel</x-ui.button>
                    @endif
                @endcan
            </div>

            {{-- schedule form --}}
            @if ($showSchedule)
                <form wire:submit="schedule" class="mt-4 space-y-3 rounded-lg border border-(--border) p-4">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-ui.input type="datetime-local" label="Scheduled at" wire:model="scheduledAt" :error="$errors->first('scheduledAt')" />
                        <x-ui.input label="Registry office" wire:model="registryOffice" :error="$errors->first('registryOffice')" />
                        <x-ui.input label="Appointment reference" wire:model="appointmentReference" />
                        <x-ui.select label="Responsible" wire:model="responsibleUser" placeholder="—" :options="$users->toArray()" />
                    </div>
                    @if ($rescheduleMode)<x-ui.input label="Reschedule reason" wire:model="scheduleReason" />@endif
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="secondary" wire:click="$set('showSchedule', false)">Cancel</x-ui.button>
                        <x-ui.button type="submit">Save appointment</x-ui.button>
                    </div>
                </form>
            @endif

            {{-- complete form --}}
            @if ($showComplete)
                <form wire:submit="complete" class="mt-4 space-y-3 rounded-lg border border-(--border) p-4">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-ui.input label="Registered document number" wire:model="registeredDocNumber" :error="$errors->first('registeredDocNumber')" />
                        <x-ui.input type="date" label="Registration date" wire:model="registrationDate" :error="$errors->first('registrationDate')" />
                    </div>
                    <div>
                        <label class="text-sm">Registered deed (optional)</label>
                        <input type="file" wire:model="registeredDeed" class="mt-1 block text-sm" />
                        @error('registeredDeed') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="secondary" wire:click="$set('showComplete', false)">Cancel</x-ui.button>
                        <x-ui.button type="submit">Complete registry</x-ui.button>
                    </div>
                </form>
            @endif

            @if ($showHold)
                <form wire:submit="putOnHold" class="mt-4 space-y-3 rounded-lg border border-(--border) p-4">
                    <x-ui.input label="Hold reason" wire:model="holdReason" :error="$errors->first('holdReason')" />
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="secondary" wire:click="$set('showHold', false)">Cancel</x-ui.button>
                        <x-ui.button type="submit">Put on hold</x-ui.button>
                    </div>
                </form>
            @endif

            {{-- appointment history --}}
            @if ($case->appointments->count() > 1)
                <div class="mt-4">
                    <p class="text-xs font-semibold uppercase tracking-wider text-(--content-muted)">Appointment history</p>
                    <ul class="mt-1 space-y-1 text-sm text-(--content-muted)">
                        @foreach ($case->appointments as $apt)
                            <li>{{ $apt->scheduled_at->format('d M Y H:i') }} · {{ $apt->registry_office }}
                                @if ($apt->superseded_at) <span class="text-xs">(superseded{{ $apt->reschedule_reason ? ' — '.$apt->reschedule_reason : '' }})</span> @else <x-ui.badge size="sm" variant="info">current</x-ui.badge> @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endif
    </x-ui.card>

    {{-- Expenses --}}
    @if ($case)
        <x-ui.card title="Registry expenses" subtitle="Tracking only — not posted to the booking financials.">
            <x-slot:actions>
                <span class="text-sm text-(--content-muted)">Total: ₹{{ number_format((float) $expenseTotal->store(), 2) }}</span>
                @can('create', App\Models\RegistryExpense::class)
                    <x-ui.button size="sm" wire:click="$toggle('showExpense')">Record</x-ui.button>
                @endcan
            </x-slot:actions>

            @if ($showExpense)
                <form wire:submit="recordExpense" class="mb-4 space-y-3 rounded-lg border border-(--border) p-4">
                    <div class="grid gap-3 sm:grid-cols-4">
                        <x-ui.select label="Type" wire:model="expenseType" :options="$expenseTypes" />
                        <x-ui.input type="number" step="0.01" label="Amount (₹)" wire:model="expenseAmount" :error="$errors->first('expenseAmount')" />
                        <x-ui.input label="Reference" wire:model="expenseReference" />
                        <x-ui.input label="Paid by" wire:model="expensePaidBy" />
                    </div>
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="secondary" wire:click="$set('showExpense', false)">Cancel</x-ui.button>
                        <x-ui.button type="submit">Record expense</x-ui.button>
                    </div>
                </form>
            @endif

            @if ($case->expenses->isEmpty())
                <p class="text-sm text-(--content-muted)">No expenses recorded.</p>
            @else
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr><th class="py-2 pr-4">Type</th><th class="py-2 pr-4 text-right">Amount</th><th class="py-2 pr-4">Reference</th><th class="py-2 pr-4">Status</th><th class="py-2 pr-4 text-right"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($case->expenses as $expense)
                            <tr wire:key="exp-{{ $expense->id }}">
                                <td class="py-2 pr-4">{{ $expense->expense_type->label() }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">₹{{ number_format((float) $expense->amount, 2) }}</td>
                                <td class="py-2 pr-4 text-(--content-muted)">{{ $expense->reference ?: '—' }}</td>
                                <td class="py-2 pr-4"><x-ui.badge :variant="$expense->isApproved() ? 'success' : 'warning'">{{ ucfirst($expense->status) }}</x-ui.badge></td>
                                <td class="py-2 pr-4 text-right">
                                    @if (! $expense->isApproved())
                                        @can('approve', $expense)
                                            <x-ui.button size="sm" variant="ghost" wire:click="approveExpense({{ $expense->id }})">Approve</x-ui.button>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-ui.card>
    @endif

    {{-- Handover --}}
    @if ($handover)
        <x-ui.card title="Document handover">
            <x-slot:actions><x-ui.badge :variant="$handover->status->color()">{{ $handover->status->label() }}</x-ui.badge></x-slot:actions>

            <dl class="grid gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-(--content-muted)">Handover date</dt><dd class="mt-0.5">{{ $handover->handover_date?->format('d M Y') ?? '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Received by</dt><dd class="mt-0.5">{{ $handover->received_by ?: '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Completed</dt><dd class="mt-0.5">{{ $handover->completed_at?->format('d M Y H:i') ?? '—' }}</dd></div>
            </dl>

            <div class="mt-4 flex flex-wrap gap-2">
                @can('update', $handover)
                    @if ($handover->status === HandoverStatus::RegistryCompleted)
                        <x-ui.button size="sm" wire:click="handoverDocsReady">Mark documents ready</x-ui.button>
                    @endif
                @endcan
                @can('complete', $handover)
                    @if (in_array($handover->status, [HandoverStatus::DocumentsReady, HandoverStatus::HandoverScheduled], true))
                        <x-ui.button size="sm" wire:click="$toggle('showHandoverComplete')">Complete handover</x-ui.button>
                    @endif
                @endcan
            </div>

            @if ($showHandoverComplete)
                <form wire:submit="completeHandover" class="mt-4 space-y-3 rounded-lg border border-(--border) p-4">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-ui.input type="date" label="Handover date" wire:model="handoverDate" :error="$errors->first('handoverDate')" />
                        <x-ui.input label="Received by (person)" wire:model="handoverReceivedBy" :error="$errors->first('handoverReceivedBy')" />
                    </div>
                    <x-ui.textarea label="Notes" wire:model="handoverNotes" rows="2" />
                    <div>
                        <label class="text-sm">Acknowledgement (optional)</label>
                        <input type="file" wire:model="handoverAck" class="mt-1 block text-sm" />
                        @error('handoverAck') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="secondary" wire:click="$set('showHandoverComplete', false)">Cancel</x-ui.button>
                        <x-ui.button type="submit">Complete handover</x-ui.button>
                    </div>
                </form>
            @endif
        </x-ui.card>
    @endif
</div>
