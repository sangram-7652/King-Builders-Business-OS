@php use App\Enums\PartnerStatus; @endphp

<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Promoters', 'url' => route('partners.index')],
        ['label' => $partner->displayName()],
    ]" />

    <x-ui.page-header :title="$partner->displayName()" :description="$partner->partner_code">
        <x-slot:actions>
            <div class="flex items-center gap-2">
                <x-ui.badge :variant="$partner->type->color()">{{ $partner->type->label() }}</x-ui.badge>
                <x-ui.badge :variant="$partner->status->color()">{{ $partner->status->label() }}</x-ui.badge>
                @can('update', $partner)
                    <x-ui.button variant="ghost" size="sm" :href="route('partners.edit', $partner)" wire:navigate>Edit</x-ui.button>
                @endcan
                @can('documents.view')
                    <x-ui.button variant="ghost" size="sm" :href="route('partners.documents', $partner)" wire:navigate>KYC</x-ui.button>
                @endcan
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Profile --}}
            <x-ui.card title="Profile">
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-(--content-muted)">Name</dt><dd>{{ $partner->name }}</dd></div>
                    <div><dt class="text-(--content-muted)">Company</dt><dd>{{ $partner->company_name ?: '—' }}</dd></div>
                    <div><dt class="text-(--content-muted)">Contact person</dt><dd>{{ $partner->contact_person ?: '—' }}</dd></div>
                    <div><dt class="text-(--content-muted)">Phone</dt><dd>{{ $partner->phone }}{{ $partner->alternate_phone ? ' / '.$partner->alternate_phone : '' }}</dd></div>
                    <div><dt class="text-(--content-muted)">Email</dt><dd>{{ $partner->email ?: '—' }}</dd></div>
                    <div><dt class="text-(--content-muted)">RERA no.</dt><dd>{{ $partner->rera_number ?: '—' }}</dd></div>
                    <div><dt class="text-(--content-muted)">Commission %</dt><dd>{{ $partner->commissionRateLabel() }}</dd></div>
                    <div><dt class="text-(--content-muted)">Location</dt><dd>{{ $partner->locationLabel() }}</dd></div>
                    <div><dt class="text-(--content-muted)">Address</dt><dd>{{ $partner->address ?: '—' }} {{ $partner->pincode }}</dd></div>
                    <div><dt class="text-(--content-muted)">PAN</dt><dd>{{ $revealSensitive && $canRevealSensitive ? ($partner->pan_number ?: '—') : ($partner->maskedPan() ?? '—') }}</dd></div>
                    <div><dt class="text-(--content-muted)">Onboarded</dt><dd>{{ $partner->onboarded_at?->format('d M Y') ?? '—' }}</dd></div>
                    <div><dt class="text-(--content-muted)">Approved by</dt><dd>{{ $partner->approvedBy?->name ?? '—' }} {{ $partner->approved_at ? '· '.$partner->approved_at->format('d M Y') : '' }}</dd></div>
                    <div><dt class="text-(--content-muted)">Created by</dt><dd>{{ $partner->createdBy?->name ?? 'System' }}</dd></div>
                </dl>
                @if ($partner->notes)
                    <p class="mt-4 rounded-lg bg-(--surface-muted) p-3 text-sm text-(--content-muted)">{{ $partner->notes }}</p>
                @endif
            </x-ui.card>

            {{-- Payout bank details --}}
            <x-ui.card title="Payout bank details" subtitle="Operational reference only. Not an accounting ledger.">
                @if ($canRevealSensitive)
                    <x-ui.button variant="ghost" size="sm" wire:click="toggleSensitive" class="mb-3">
                        {{ $revealSensitive ? 'Hide' : 'Reveal' }} account number
                    </x-ui.button>
                @endif
                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-(--content-muted)">Account holder</dt><dd>{{ $partner->bank_account_name ?: '—' }}</dd></div>
                    <div><dt class="text-(--content-muted)">Account number</dt><dd>{{ $revealSensitive && $canRevealSensitive ? ($partner->bank_account_number ?: '—') : ($partner->maskedBankAccount() ?? '—') }}</dd></div>
                    <div><dt class="text-(--content-muted)">IFSC</dt><dd>{{ $partner->bank_ifsc ?: '—' }}</dd></div>
                    <div><dt class="text-(--content-muted)">Bank</dt><dd>{{ $partner->bank_name ?: '—' }}</dd></div>
                </dl>
            </x-ui.card>

            {{-- Promoter Advance ledger (§7) --}}
            <x-ui.card title="Promoter Advance ledger" subtitle="Every advance / commission movement, oldest first. Balance is always derived from this history.">
                @if ($partner->ledgerEntries->isEmpty())
                    <x-ui.empty-state icon="wallet" title="No ledger activity yet" />
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-(--border) text-left text-xs uppercase tracking-wider text-(--content-muted)">
                                    <th class="py-2 pr-3">Date</th>
                                    <th class="py-2 pr-3">Type</th>
                                    <th class="py-2 pr-3">Reference</th>
                                    <th class="py-2 pr-3 text-right">Commission</th>
                                    <th class="py-2 pr-3 text-right">Advance</th>
                                    <th class="py-2 pr-3 text-right">Adjustment</th>
                                    <th class="py-2 pr-3 text-right">Payable</th>
                                    <th class="py-2 pr-3 text-right">Balance</th>
                                    <th class="py-2">Remark</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-(--border)">
                                @foreach ($partner->ledgerEntries->reverse() as $entry)
                                    <tr wire:key="ledger-{{ $entry->id }}" class="{{ $entry->reversed_at ? 'opacity-60' : '' }}">
                                        <td class="py-2 pr-3 whitespace-nowrap">{{ $entry->created_at?->format('d M Y') }}</td>
                                        <td class="py-2 pr-3 whitespace-nowrap">
                                            {{ $entry->type->label() }}
                                            @if ($entry->reversed_at) <x-ui.badge variant="muted" size="sm">reversed</x-ui.badge> @endif
                                        </td>
                                        <td class="py-2 pr-3 whitespace-nowrap">{{ $entry->booking?->booking_number ?? $entry->reference ?? '—' }}</td>
                                        <td class="py-2 pr-3 text-right tabular-nums">{{ $entry->gross_commission_amount !== null ? '₹'.number_format((float) $entry->gross_commission_amount, 2) : '—' }}</td>
                                        <td class="py-2 pr-3 text-right tabular-nums">{{ $entry->advance_amount !== null ? '₹'.number_format((float) $entry->advance_amount, 2) : '—' }}</td>
                                        <td class="py-2 pr-3 text-right tabular-nums">{{ $entry->adjustment_amount !== null ? '₹'.number_format((float) $entry->adjustment_amount, 2) : '—' }}</td>
                                        <td class="py-2 pr-3 text-right tabular-nums">{{ $entry->payable_amount !== null ? '₹'.number_format((float) $entry->payable_amount, 2) : '—' }}</td>
                                        <td class="py-2 pr-3 text-right font-medium tabular-nums">₹{{ number_format((float) $entry->balance_after, 2) }}</td>
                                        <td class="py-2 text-(--content-muted)">{{ $entry->description ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>

            {{-- Project authorizations --}}
            <x-ui.card title="Project authorisation" subtitle="Projects this partner is cleared to source bookings for.">
                @can('authorizeProjects', $partner)
                    <form wire:submit="authorizeProject" class="mb-4 flex flex-wrap items-end gap-3">
                        <div class="min-w-56 flex-1">
                            <x-ui.select label="Authorise for project" wire:model="authorizeProjectId" placeholder="Select a project…"
                                :options="$assignableProjects->toArray()" :error="$errors->first('authorizeProjectId')" />
                        </div>
                        <x-ui.button type="submit" size="sm">Authorise</x-ui.button>
                    </form>
                @endcan

                @if ($partner->projectAuthorizations->isEmpty())
                    <x-ui.empty-state icon="building" title="No project authorisations yet" />
                @else
                    <ul class="divide-y divide-(--border) text-sm">
                        @foreach ($partner->projectAuthorizations as $auth)
                            <li wire:key="auth-{{ $auth->id }}" class="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span>
                                    <span class="font-medium">{{ $auth->project?->name ?? '—' }}</span>
                                    <x-ui.badge :variant="$auth->status === 'active' ? 'success' : 'muted'" size="sm">{{ ucfirst($auth->status) }}</x-ui.badge>
                                </span>
                                <span class="flex items-center gap-2 text-xs text-(--content-muted)">
                                    @if ($auth->status === 'active')
                                        authorised {{ $auth->authorized_at?->format('d M Y') }} by {{ $auth->authorizedBy?->name ?? 'System' }}
                                        @can('authorizeProjects', $partner)
                                            <x-ui.button variant="ghost" size="sm" class="text-red-600"
                                                wire:click="revokeProject({{ $auth->id }})" wire:confirm="Revoke this authorisation?">Revoke</x-ui.button>
                                        @endcan
                                    @else
                                        revoked {{ $auth->revoked_at?->format('d M Y') }}
                                        @if ($auth->revoke_reason) — {{ $auth->revoke_reason }} @endif
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>

            {{-- Activity timeline --}}
            <x-ui.card title="Activity">
                @if ($partner->activities->isEmpty())
                    <x-ui.empty-state icon="clock" title="No activity" />
                @else
                    <ol class="space-y-4">
                        @foreach ($partner->activities as $act)
                            <li wire:key="pact-{{ $act->id }}" class="flex gap-3">
                                <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-(--surface-muted) text-(--content-muted)">
                                    <x-app.icon :name="$act->type->icon()" class="size-4" />
                                </span>
                                <div class="text-sm">
                                    <p class="text-(--content)">{{ $act->description }}</p>
                                    <p class="text-xs text-(--content-muted)">{{ $act->causer?->name ?? 'System' }} · {{ $act->created_at?->diffForHumans() }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-ui.card>
        </div>

        <div class="space-y-6">
            {{-- Lifecycle --}}
            <x-ui.card title="Lifecycle">
                <p class="text-sm text-(--content-muted)">Current status</p>
                <p class="mt-1"><x-ui.badge :variant="$partner->status->color()">{{ $partner->status->label() }}</x-ui.badge></p>

                @can('changeStatus', $partner)
                    @if (count($allowedTransitions) > 0)
                        <div class="mt-4 space-y-2">
                            <p class="text-xs font-semibold uppercase tracking-wider text-(--content-muted)">Move to</p>
                            @foreach ($allowedTransitions as $target)
                                @php $needsApprove = $target === PartnerStatus::Active; @endphp
                                @if (! $needsApprove || auth()->user()->can('approve', $partner))
                                    <x-ui.button variant="ghost" size="sm" class="w-full justify-start"
                                        wire:click="changeStatus('{{ $target->value }}')"
                                        wire:confirm="Move partner to {{ $target->label() }}?">
                                        {{ $target->label() }}
                                    </x-ui.button>
                                @endif
                            @endforeach
                        </div>
                    @endif
                @endcan
            </x-ui.card>

            {{-- Promoter Advance dashboard (§6) --}}
            <x-ui.card title="Promoter Advance">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-(--content-muted)">Commission rate</dt><dd class="font-medium">{{ $partner->commissionRateLabel() }}</dd></div>
                    <div class="flex justify-between"><dt class="text-(--content-muted)">Total bookings</dt><dd class="tabular-nums">{{ $stats['totalBookings'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-(--content-muted)">Total booking value</dt><dd class="tabular-nums">₹{{ number_format((float) $stats['totalBookingValue']->store(), 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-(--content-muted)">Gross commission</dt><dd class="tabular-nums">₹{{ number_format((float) $stats['totalCommissionEarned']->store(), 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-(--content-muted)">Advance received</dt><dd class="tabular-nums">₹{{ number_format((float) $stats['totalAdvanceGiven']->store(), 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-(--content-muted)">Advance adjusted</dt><dd class="tabular-nums">₹{{ number_format((float) $stats['totalAdvanceAdjusted']->store(), 2) }}</dd></div>
                    <div class="flex justify-between border-t border-(--border) pt-2 font-semibold"><dt>Advance balance</dt><dd class="tabular-nums">₹{{ number_format((float) $stats['advanceBalance']->store(), 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-(--content-muted)">Commission payable</dt><dd class="tabular-nums">₹{{ number_format((float) $stats['totalCommissionPayable']->store(), 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-(--content-muted)">Commission paid</dt><dd class="tabular-nums">₹{{ number_format((float) $stats['totalCommissionPaid']->store(), 2) }}</dd></div>
                </dl>

                @can('update', $partner)
                    <div class="mt-4 space-y-3 border-t border-(--border) pt-4">
                        <form wire:submit="giveAdvance" class="space-y-2">
                            <p class="text-xs font-semibold uppercase tracking-wider text-(--content-muted)">Add advance</p>
                            <x-ui.input type="number" step="0.01" label="Amount (₹)" wire:model="advanceAmount" :error="$errors->first('advanceAmount')" />
                            <x-ui.input label="Note (optional)" wire:model="advanceNote" :error="$errors->first('advanceNote')" />
                            <x-ui.button type="submit" size="sm">Add advance</x-ui.button>
                        </form>

                        <form wire:submit="refundAdvance" class="space-y-2">
                            <p class="text-xs font-semibold uppercase tracking-wider text-(--content-muted)">Advance adjustment / refund</p>
                            <x-ui.input type="number" step="0.01" label="Amount (₹)" wire:model="refundAmount" :error="$errors->first('refundAmount')" />
                            <x-ui.input label="Reason" wire:model="refundReason" :error="$errors->first('refundReason')" />
                            <x-ui.button type="submit" variant="secondary" size="sm">Record adjustment</x-ui.button>
                        </form>
                    </div>
                @endcan
            </x-ui.card>

            {{-- KYC summary --}}
            <x-ui.card title="KYC">
                <div class="grid grid-cols-2 gap-3 text-center">
                    <div><p class="text-2xl font-semibold">{{ $kyc->verifiedCount }}/{{ $kyc->requiredCount }}</p><p class="text-xs text-(--content-muted)">Verified</p></div>
                    <div><p class="text-2xl font-semibold">{{ $kyc->pendingCount }}</p><p class="text-xs text-(--content-muted)">Pending</p></div>
                </div>
                @if ($kyc->isComplete())
                    <x-ui.badge variant="success" class="mt-3">KYC complete</x-ui.badge>
                @else
                    <x-ui.badge variant="warning" class="mt-3">KYC incomplete</x-ui.badge>
                @endif
                @can('documents.view')
                    <x-ui.button variant="ghost" size="sm" class="mt-3 w-full" :href="route('partners.documents', $partner)" wire:navigate>Manage KYC documents</x-ui.button>
                @endcan
            </x-ui.card>

            {{-- Attribution 360 (M14.2) --}}
            <x-ui.card title="Attributed business">
                <div class="grid grid-cols-1 gap-3 text-center">
                    <div><p class="text-2xl font-semibold">{{ $partner->booking_attributions_count }}</p><p class="text-xs text-(--content-muted)">Bookings</p></div>
                </div>

                @if ($recentBookings->isNotEmpty())
                    <p class="mt-4 text-xs font-semibold uppercase tracking-wider text-(--content-muted)">Recent bookings</p>
                    <ul class="mt-1 space-y-1 text-sm">
                        @foreach ($recentBookings as $att)
                            <li wire:key="pb-{{ $att->id }}" class="flex items-center justify-between gap-2">
                                @can('bookings.view')
                                    <a href="{{ route('bookings.show', $att->booking_id) }}" wire:navigate class="text-(--brand-primary) hover:underline">{{ $att->booking?->booking_number }}</a>
                                @else
                                    <span>{{ $att->booking?->booking_number }}</span>
                                @endcan
                                <span class="text-xs text-(--content-muted)">{{ $att->booking?->status?->label() }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>
    </div>
</div>
