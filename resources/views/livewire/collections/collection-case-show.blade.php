@php use App\Enums\PenaltyStatus; @endphp
<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Collection queue', 'url' => route('collections.queue')],
        ['label' => $case->booking->booking_number],
    ]" />

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold tracking-tight text-(--content)">{{ $case->booking->booking_number }}</h1>
                <x-ui.badge :variant="$case->status->color()">{{ $case->status->label() }}</x-ui.badge>
                <x-ui.badge :variant="$case->priority->color()">{{ $case->priority->label() }} priority</x-ui.badge>
            </div>
            <p class="mt-1 text-sm text-(--content-muted)">
                {{ $case->booking->primaryBookingBuyer?->buyer?->fullName() }} ·
                {{ $case->booking->project?->name }} · Plot {{ $case->booking->plot?->plot_number }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <x-ui.button variant="secondary" size="sm" :href="route('payments.booking', $case->booking)" wire:navigate>Payments</x-ui.button>
            @can('update', $case)
                @foreach ($statuses as $value => $label)
                    @if ($value !== $case->status->value)
                        <x-ui.button size="sm" variant="ghost" wire:click="setStatus('{{ $value }}')">→ {{ $label }}</x-ui.button>
                    @endif
                @endforeach
            @endcan
        </div>
    </div>

    {{-- Money position (M7 truth) --}}
    <div class="grid gap-4 sm:grid-cols-4">
        <x-ui.stat-card label="Total" :value="'₹'.number_format((float) $summary->total->store(), 2)" />
        <x-ui.stat-card label="Paid" :value="'₹'.number_format((float) $summary->paid->store(), 2)" />
        <x-ui.stat-card label="Outstanding" :value="'₹'.number_format((float) $summary->outstanding->store(), 2)" />
        <x-ui.stat-card label="Overdue" :value="'₹'.number_format((float) $summary->overdue->store(), 2)" />
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Owner + overdue installments --}}
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Overdue installments">
                @if ($overdueInstallments->isEmpty())
                    <p class="text-sm text-(--content-muted)">Nothing overdue right now.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-(--border) text-sm">
                            <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                                <tr><th class="py-2 pr-4">Installment</th><th class="py-2 pr-4">Due</th>
                                    <th class="py-2 pr-4 text-right">Amount</th><th class="py-2 pr-4 text-right">Outstanding</th>
                                    <th class="py-2 pr-4 text-right">Days</th><th class="py-2 pr-4">Bucket</th></tr>
                            </thead>
                            <tbody class="divide-y divide-(--border)">
                                @foreach ($overdueInstallments as $line)
                                    <tr>
                                        <td class="py-2 pr-4">{{ $line['installment']->label() }}</td>
                                        <td class="py-2 pr-4">{{ $line['installment']->due_date->format('d M Y') }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">₹{{ number_format((float) $line['installment']->amount, 2) }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">₹{{ number_format((float) $line['outstanding']->store(), 2) }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ $line['days_overdue'] }}</td>
                                        <td class="py-2 pr-4"><x-ui.badge :variant="$line['bucket']->color()">{{ $line['bucket']->label() }}</x-ui.badge></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>

            {{-- Follow-ups --}}
            <x-ui.card title="Follow-ups">
                <x-slot:actions>
                    @can('followUp', $case)
                        <x-ui.button size="sm" wire:click="$toggle('showSchedule')">Schedule</x-ui.button>
                    @endcan
                </x-slot:actions>

                @if ($showSchedule)
                    <form wire:submit="scheduleFollowUp" class="mb-4 space-y-3 rounded-lg border border-(--border) p-3">
                        <x-ui.input type="datetime-local" label="Follow-up at" wire:model="scheduleAt" :error="$errors->first('scheduleAt')" />
                        <x-ui.textarea label="Notes" wire:model="scheduleNotes" rows="2" />
                        <div class="flex justify-end gap-2">
                            <x-ui.button type="button" variant="secondary" wire:click="$set('showSchedule', false)">Cancel</x-ui.button>
                            <x-ui.button type="submit">Schedule</x-ui.button>
                        </div>
                    </form>
                @endif

                <ul class="space-y-3 text-sm">
                    @forelse ($case->followUps as $fu)
                        <li wire:key="fu-{{ $fu->id }}" class="rounded-lg border border-(--border) p-3">
                            <div class="flex items-center justify-between">
                                <span class="font-medium">{{ $fu->follow_up_at->format('d M Y H:i') }}</span>
                                @if ($fu->isCompleted())
                                    <x-ui.badge variant="success">{{ $fu->outcome?->label() }}</x-ui.badge>
                                @else
                                    <x-ui.badge :variant="$fu->isOverdue() ? 'danger' : 'warning'">Pending</x-ui.badge>
                                @endif
                            </div>
                            @if ($fu->notes)<p class="mt-1 text-(--content-muted)">{{ $fu->notes }}</p>@endif
                            @if (! $fu->isCompleted())
                                @can('followUp', $case)
                                    @if ($completingId === $fu->id)
                                        <form wire:submit="completeFollowUp" class="mt-2 space-y-2">
                                            <x-ui.select label="Outcome" wire:model="completeOutcome" placeholder="Select…" :options="$outcomes" :error="$errors->first('completeOutcome')" />
                                            <x-ui.textarea label="Notes" wire:model="completeNotes" rows="2" />
                                            <x-ui.input type="datetime-local" label="Next follow-up (optional)" wire:model="completeNext" />
                                            <div class="flex justify-end gap-2">
                                                <x-ui.button type="button" size="sm" variant="secondary" wire:click="$set('completingId', null)">Cancel</x-ui.button>
                                                <x-ui.button type="submit" size="sm">Save outcome</x-ui.button>
                                            </div>
                                        </form>
                                    @else
                                        <x-ui.button size="sm" variant="ghost" class="mt-2" wire:click="$set('completingId', {{ $fu->id }})">Record outcome</x-ui.button>
                                    @endif
                                @endcan
                            @endif
                        </li>
                    @empty
                        <li class="text-(--content-muted)">No follow-ups yet.</li>
                    @endforelse
                </ul>
            </x-ui.card>

            {{-- Promises --}}
            <x-ui.card title="Promises to pay" subtitle="A promise is not a payment — it never changes the paid amount.">
                <x-slot:actions>
                    @can('create', App\Models\PaymentPromise::class)
                        <x-ui.button size="sm" wire:click="$toggle('showPromise')">New promise</x-ui.button>
                    @endcan
                </x-slot:actions>

                @if ($showPromise)
                    <form wire:submit="createPromise" class="mb-4 space-y-3 rounded-lg border border-(--border) p-3">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-ui.input type="number" step="0.01" label="Promised amount (₹)" wire:model="promiseAmount" :error="$errors->first('promiseAmount')" />
                            <x-ui.input type="date" label="Promise date" wire:model="promiseDate" :error="$errors->first('promiseDate')" />
                        </div>
                        <x-ui.textarea label="Notes" wire:model="promiseNotes" rows="2" />
                        <div class="flex justify-end gap-2">
                            <x-ui.button type="button" variant="secondary" wire:click="$set('showPromise', false)">Cancel</x-ui.button>
                            <x-ui.button type="submit">Record promise</x-ui.button>
                        </div>
                    </form>
                @endif

                <ul class="space-y-2 text-sm">
                    @forelse ($case->promises as $promise)
                        <li wire:key="pr-{{ $promise->id }}" class="flex items-center justify-between rounded-lg border border-(--border) p-3">
                            <div>
                                <span class="font-medium">₹{{ number_format((float) $promise->promised_amount, 2) }}</span>
                                by {{ $promise->promise_date->format('d M Y') }}
                                <x-ui.badge :variant="$promise->status->color()" size="sm" class="ml-1">{{ $promise->status->label() }}</x-ui.badge>
                            </div>
                            @if ($promise->isOpen())
                                @can('update', $promise)
                                    <x-ui.button size="sm" variant="ghost" class="text-red-600" wire:click="cancelPromise({{ $promise->id }})">Cancel</x-ui.button>
                                @endcan
                            @endif
                        </li>
                    @empty
                        <li class="text-(--content-muted)">No promises recorded.</li>
                    @endforelse
                </ul>
            </x-ui.card>

            {{-- Cheques --}}
            <x-ui.card title="Cheques">
                @if ($chequePayments->isEmpty())
                    <p class="text-sm text-(--content-muted)">No cheque payments on this booking.</p>
                @else
                    <ul class="space-y-2 text-sm">
                        @foreach ($chequePayments as $cp)
                            <li wire:key="chq-{{ $cp->id }}" class="rounded-lg border border-(--border) p-3">
                                <div class="flex items-center justify-between">
                                    <span>Cheque {{ $cp->cheque_number }} — ₹{{ number_format((float) $cp->amount, 2) }}</span>
                                    <x-ui.badge :variant="$cp->cheque_status?->color() ?? 'muted'">{{ $cp->cheque_status?->label() }}</x-ui.badge>
                                </div>
                                @if (! $cp->chequeBounce)
                                    @can('bounce', App\Models\ChequeBounce::class)
                                        @if ($bouncingPaymentId === $cp->id)
                                            <form wire:submit="recordBounce" class="mt-2 space-y-2">
                                                <x-ui.input type="date" label="Bounce date" wire:model="bounceDate" :error="$errors->first('bounceDate')" />
                                                <x-ui.input label="Reason" wire:model="bounceReason" :error="$errors->first('bounceReason')" />
                                                <x-ui.input type="number" step="0.01" label="Bank charges" wire:model="bounceCharges" />
                                                <div class="flex justify-end gap-2">
                                                    <x-ui.button type="button" size="sm" variant="secondary" wire:click="$set('bouncingPaymentId', null)">Cancel</x-ui.button>
                                                    <x-ui.button type="submit" size="sm" variant="danger">Record bounce</x-ui.button>
                                                </div>
                                            </form>
                                        @else
                                            <x-ui.button size="sm" variant="ghost" class="mt-2 text-red-600" wire:click="$set('bouncingPaymentId', {{ $cp->id }})">Record bounce</x-ui.button>
                                        @endif
                                    @endcan
                                @else
                                    <p class="mt-1 text-xs text-(--content-muted)">Bounced {{ $cp->chequeBounce->bounce_date->format('d M Y') }} — {{ $cp->chequeBounce->bounce_reason }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            {{-- Penalties --}}
            @if ($case->chequeBounces->isNotEmpty())
                <x-ui.card title="Bounce penalties" subtitle="Assessment foundation — not posted to the booking total.">
                    @foreach ($case->chequeBounces as $bounce)
                        <div wire:key="bnc-{{ $bounce->id }}" class="mb-3 rounded-lg border border-(--border) p-3 text-sm">
                            <p class="font-medium">Cheque {{ $bounce->payment?->cheque_number }} · bounced {{ $bounce->bounce_date->format('d M Y') }}</p>
                            @forelse ($bounce->penalties as $penalty)
                                <div class="mt-1 flex items-center justify-between">
                                    <span>₹{{ number_format((float) $penalty->penalty_amount, 2) }} — {{ $penalty->reason }}</span>
                                    <span class="flex items-center gap-2">
                                        <x-ui.badge :variant="$penalty->status->color()">{{ $penalty->status->label() }}</x-ui.badge>
                                        @if ($penalty->status === PenaltyStatus::Assessed)
                                            @can('approve', $penalty)
                                                <x-ui.button size="sm" variant="ghost" wire:click="approvePenalty({{ $penalty->id }})">Approve</x-ui.button>
                                            @endcan
                                        @endif
                                    </span>
                                </div>
                            @empty
                                <p class="mt-1 text-(--content-muted)">No penalty assessed.</p>
                            @endforelse
                            @can('assess', App\Models\BouncePenalty::class)
                                @if ($assessingBounceId === $bounce->id)
                                    <form wire:submit="assessPenalty" class="mt-2 space-y-2">
                                        <x-ui.input type="number" step="0.01" label="Penalty amount (₹)" wire:model="penaltyAmount" :error="$errors->first('penaltyAmount')" />
                                        <x-ui.input label="Reason" wire:model="penaltyReason" :error="$errors->first('penaltyReason')" />
                                        <div class="flex justify-end gap-2">
                                            <x-ui.button type="button" size="sm" variant="secondary" wire:click="$set('assessingBounceId', null)">Cancel</x-ui.button>
                                            <x-ui.button type="submit" size="sm">Assess</x-ui.button>
                                        </div>
                                    </form>
                                @else
                                    <x-ui.button size="sm" variant="ghost" class="mt-2" wire:click="$set('assessingBounceId', {{ $bounce->id }})">Assess penalty</x-ui.button>
                                @endif
                            @endcan
                        </div>
                    @endforeach
                </x-ui.card>
            @endif
        </div>

        {{-- Sidebar: owner + timeline --}}
        <div class="space-y-6">
            <x-ui.card title="Owner">
                @can('assign', $case)
                    <form wire:submit="assign" class="space-y-2">
                        <x-ui.select label="Assigned to" wire:model="assignTo" placeholder="Unassigned">
                            @foreach ($owners as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        </x-ui.select>
                        <x-ui.button type="submit" size="sm">Update</x-ui.button>
                    </form>
                @else
                    <p class="text-sm">{{ $case->assignedTo?->name ?? 'Unassigned' }}</p>
                @endcan
                <p class="mt-3 text-xs text-(--content-muted)">
                    Opened {{ $case->opened_at?->format('d M Y') }}{{ $case->openedBy ? ' by '.$case->openedBy->name : '' }}.
                </p>
            </x-ui.card>

            <x-ui.card title="Timeline">
                <ol class="space-y-3 text-sm">
                    @foreach ($case->activities as $activity)
                        <li wire:key="act-{{ $activity->id }}" class="border-l-2 border-(--border) pl-3">
                            <p class="font-medium">{{ $activity->type->label() }}</p>
                            <p class="text-(--content-muted)">{{ $activity->description }}</p>
                            <p class="text-xs text-(--content-muted)">{{ $activity->created_at?->format('d M Y H:i') }}{{ $activity->causer ? ' · '.$activity->causer->name : '' }}</p>
                        </li>
                    @endforeach
                </ol>
            </x-ui.card>
        </div>
    </div>
</div>
