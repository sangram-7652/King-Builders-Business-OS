@php
    use App\Enums\CommissionCaseStatus;
    use App\Enums\CommissionPayoutStatus;
    $calc = $case->currentCalculation;
@endphp

<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Commissions', 'url' => route('commissions.index')],
        ['label' => $case->case_number],
    ]" />

    <x-ui.page-header :title="$case->case_number"
        :description="$case->partner?->displayName().' · booking '.$case->booking?->booking_number">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.badge :variant="$case->status->color()">{{ $case->status->label() }}</x-ui.badge>
                @can('recalculate', $case)
                    <x-ui.button variant="secondary" size="sm" wire:click="recalculate" wire:confirm="Recalculate against the current booking figures?">Recalculate</x-ui.button>
                @endcan
                @can('approve', $case)
                    <x-ui.button size="sm" wire:click="approve" wire:confirm="Approve this commission?">Approve</x-ui.button>
                @endcan
                @can('resume', $case)
                    <x-ui.button variant="secondary" size="sm" wire:click="resume">Resume</x-ui.button>
                @endcan
                @can('hold', $case)
                    <x-ui.button variant="ghost" size="sm" wire:click="startReason('hold')">Hold</x-ui.button>
                @endcan
                @can('cancel', $case)
                    <x-ui.button variant="ghost" size="sm" class="text-red-600" wire:click="startReason('cancel')">Cancel</x-ui.button>
                @endcan
                @can('reverse', $case)
                    <x-ui.button variant="ghost" size="sm" class="text-red-600" wire:click="startReason('reverse')">Reverse</x-ui.button>
                @endcan
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    @unless ($case->is_eligible)
        <x-ui.alert variant="warning" title="Not eligible">{{ $case->eligibility_reason }}</x-ui.alert>
    @endunless
    @if ($case->status === CommissionCaseStatus::OnHold && $case->hold_reason)
        <x-ui.alert variant="muted" title="On hold">{{ $case->hold_reason }}</x-ui.alert>
    @endif
    @if ($case->status === CommissionCaseStatus::Reversed)
        <x-ui.alert variant="danger" title="Reversed">
            {{ $case->reversal_reason }}
            @if ((float) $case->clawback_amount > 0) — clawback of ₹{{ number_format((float) $case->clawback_amount, 2) }} to recover. @endif
        </x-ui.alert>
    @endif

    {{-- reason prompt --}}
    @if ($pendingAction)
        <x-ui.card :title="ucfirst($pendingAction).' — reason'">
            <form wire:submit="submitReason" class="flex flex-wrap items-end gap-3">
                <div class="min-w-64 flex-1"><x-ui.input label="Reason" wire:model="reasonInput" :error="$errors->first('reasonInput')" /></div>
                <x-ui.button type="submit">Confirm {{ $pendingAction }}</x-ui.button>
                <x-ui.button type="button" variant="ghost" wire:click="$set('pendingAction', null)">Cancel</x-ui.button>
            </form>
        </x-ui.card>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Commission" class="lg:col-span-2">
            @if ($calc)
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-(--content-muted)">Booking value</dt><dd>₹{{ number_format((float) $calc->basis_amount, 2) }}</dd></div>
                    <div><dt class="text-(--content-muted)">Commission %</dt><dd>{{ $case->partner?->commissionRateLabel() ?? '—' }}</dd></div>
                    <div><dt class="text-(--content-muted)">Gross commission</dt><dd class="tabular-nums">₹{{ number_format((float) $calc->commission_amount, 2) }}</dd></div>
                    <div><dt class="text-(--content-muted)">Advance adjusted</dt><dd class="tabular-nums">₹{{ number_format((float) $calc->advance_adjusted_amount, 2) }}</dd></div>
                    <div class="sm:col-span-2 border-t border-(--border) pt-2">
                        <dt class="text-(--content-muted)">Net payable commission</dt>
                        <dd class="text-lg font-semibold tabular-nums">₹{{ number_format((float) $calc->payable_amount, 2) }}</dd>
                    </div>
                </dl>
            @else
                <x-ui.empty-state icon="layers" title="No calculation" description="{{ $case->eligibility_reason }}" />
            @endif
        </x-ui.card>

        <x-ui.card title="Case">
            <dl class="space-y-2 text-sm">
                <div><dt class="text-(--content-muted)">Booking</dt>
                    <dd><a href="{{ route('bookings.show', $case->booking_id) }}" wire:navigate class="text-(--brand-primary) hover:underline">{{ $case->booking?->booking_number }}</a> ({{ $case->booking?->status->label() }})</dd></div>
                <div><dt class="text-(--content-muted)">Partner</dt>
                    <dd><a href="{{ route('partners.show', $case->partner_id) }}" wire:navigate class="text-(--brand-primary) hover:underline">{{ $case->partner?->displayName() }}</a></dd></div>
                <div><dt class="text-(--content-muted)">Approved by</dt><dd>{{ $case->approvedBy?->name ?? '—' }} {{ $case->approved_at ? '· '.$case->approved_at->format('d M Y') : '' }}</dd></div>
                <div><dt class="text-(--content-muted)">Gross commission</dt><dd class="tabular-nums">₹{{ number_format((float) $case->commission_amount, 2) }}</dd></div>
                <div><dt class="text-(--content-muted)">Advance adjusted</dt><dd class="tabular-nums">₹{{ number_format((float) $case->advance_adjusted_amount, 2) }}</dd></div>
                <div><dt class="text-(--content-muted)">Payable</dt><dd class="tabular-nums">₹{{ number_format((float) $case->payable_amount, 2) }}</dd></div>
                <div><dt class="text-(--content-muted)">Paid</dt><dd class="tabular-nums">₹{{ number_format((float) $case->paid_amount, 2) }}</dd></div>
                <div><dt class="text-(--content-muted)">Outstanding</dt><dd class="tabular-nums font-medium">₹{{ number_format((float) $case->outstandingAmount(), 2) }}</dd></div>
            </dl>
        </x-ui.card>
    </div>

    {{-- Payouts --}}
    <x-ui.card title="Payouts" subtitle="Operational tracking only — this is not an accounting ledger.">
        <x-slot:actions>
            @can('recordPayout', $case)
                <x-ui.button size="sm" wire:click="openPayout">Record payout</x-ui.button>
            @endcan
        </x-slot:actions>

        @if ($showPayout)
            <form wire:submit="recordPayout" class="mb-4 grid gap-3 rounded-lg border border-(--border) p-3 sm:grid-cols-2">
                <x-ui.input label="Amount ₹" type="number" step="0.01" wire:model="payoutAmount" :error="$errors->first('payoutAmount')" />
                <x-ui.select label="Method" wire:model="payoutMethod" :options="$payoutMethods" :placeholder="null" :error="$errors->first('payoutMethod')" />
                <x-ui.date-input label="Paid on" wire:model="payoutDate" :error="$errors->first('payoutDate')" />
                <x-ui.input label="Reference" wire:model="payoutReference" :error="$errors->first('payoutReference')" />
                <div class="sm:col-span-2"><x-ui.input label="Notes" wire:model="payoutNotes" :error="$errors->first('payoutNotes')" /></div>
                <div class="sm:col-span-2 flex gap-2">
                    <x-ui.button type="submit">Save payout</x-ui.button>
                    <x-ui.button type="button" variant="ghost" wire:click="$set('showPayout', false)">Cancel</x-ui.button>
                </div>
            </form>
        @endif

        @if ($case->payouts->isEmpty())
            <p class="text-sm text-(--content-muted)">No payouts recorded.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr><th class="py-2 pr-4">Date</th><th class="py-2 pr-4">Method</th><th class="py-2 pr-4">Reference</th><th class="py-2 pr-4 text-right">Amount</th><th class="py-2 pr-4">Status</th><th class="py-2 pr-4">By</th><th class="py-2 pr-4"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($case->payouts as $payout)
                            <tr wire:key="po-{{ $payout->id }}" @class(['text-(--content-muted) line-through' => $payout->isVoided()])>
                                <td class="py-2 pr-4">{{ $payout->paid_on->format('d M Y') }}</td>
                                <td class="py-2 pr-4">{{ $payout->method->label() }}</td>
                                <td class="py-2 pr-4">{{ $payout->reference ?: '—' }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">₹{{ number_format((float) $payout->amount, 2) }}</td>
                                <td class="py-2 pr-4"><x-ui.badge :variant="$payout->status->color()" size="sm">{{ $payout->status->label() }}</x-ui.badge></td>
                                <td class="py-2 pr-4">{{ $payout->recordedBy?->name ?? 'System' }}</td>
                                <td class="py-2 pr-4 text-right">
                                    @if (! $payout->isVoided() && $case->status !== CommissionCaseStatus::Reversed)
                                        @can('voidPayout', $case)
                                            <x-ui.button variant="ghost" size="sm" class="text-red-600" wire:click="voidPayout({{ $payout->id }})" wire:confirm="Void this payout?">Void</x-ui.button>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.card>

    @if ($case->calculations->count() > 1)
        <x-ui.card title="Calculation history" subtitle="Every run is kept immutably.">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr><th class="py-2 pr-4">#</th><th class="py-2 pr-4 text-right">Booking value</th><th class="py-2 pr-4 text-right">Gross</th><th class="py-2 pr-4 text-right">Adjusted</th><th class="py-2 pr-4 text-right">Payable</th><th class="py-2 pr-4">When</th></tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($case->calculations as $c)
                            <tr @class(['font-medium' => $c->id === $case->current_calculation_id])>
                                <td class="py-2 pr-4 tabular-nums">{{ $c->sequence }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">₹{{ number_format((float) $c->basis_amount, 2) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">₹{{ number_format((float) $c->commission_amount, 2) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">₹{{ number_format((float) $c->advance_adjusted_amount, 2) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">₹{{ number_format((float) $c->payable_amount, 2) }}</td>
                                <td class="py-2 pr-4">{{ $c->calculated_at->format('d M Y H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif

    @if ($case->ledgerEntries->isNotEmpty())
        <x-ui.card title="Promoter Advance ledger footprint" subtitle="This case's advance movement history — never edited, only compensated.">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-(--border) text-sm">
                    <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                        <tr><th class="py-2 pr-4">Date</th><th class="py-2 pr-4">Type</th><th class="py-2 pr-4 text-right">Amount</th><th class="py-2 pr-4">Remark</th><th class="py-2 pr-4">By</th></tr>
                    </thead>
                    <tbody class="divide-y divide-(--border)">
                        @foreach ($case->ledgerEntries as $entry)
                            <tr wire:key="cle-{{ $entry->id }}" @class(['text-(--content-muted)' => $entry->reversed_at !== null])>
                                <td class="py-2 pr-4">{{ $entry->created_at?->format('d M Y') }}</td>
                                <td class="py-2 pr-4">{{ $entry->type->label() }} @if ($entry->reversed_at) <x-ui.badge variant="muted" size="sm">reversed</x-ui.badge> @endif</td>
                                <td class="py-2 pr-4 text-right tabular-nums">₹{{ number_format((float) ($entry->adjustment_amount ?? $entry->advance_amount ?? 0), 2) }}</td>
                                <td class="py-2 pr-4">{{ $entry->description ?: '—' }}</td>
                                <td class="py-2 pr-4">{{ $entry->createdBy?->name ?? 'System' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif

    <x-ui.card title="Timeline">
        @if ($case->events->isEmpty())
            <x-ui.empty-state icon="clock" title="No events" />
        @else
            <ol class="space-y-3">
                @foreach ($case->events as $event)
                    <li wire:key="ce-{{ $event->id }}" class="flex gap-3 text-sm">
                        <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-(--surface-muted) text-(--content-muted)">
                            <x-app.icon :name="$event->type->icon()" class="size-4" />
                        </span>
                        <div>
                            <p>{{ $event->description }}</p>
                            <p class="text-xs text-(--content-muted)">{{ $event->causer?->name ?? 'System' }} · {{ $event->created_at?->diffForHumans() }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-ui.card>
</div>
