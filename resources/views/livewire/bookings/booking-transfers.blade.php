@php
    use App\Enums\TransferRequestStatus as TS;
@endphp
<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Bookings', 'url' => route('bookings.index')],
        ['label' => $booking->booking_number, 'url' => route('bookings.show', $booking)],
        ['label' => 'Transfers'],
    ]" />

    <x-ui.page-header :title="'Transfers — '.$booking->booking_number"
        description="Ownership / nominee transfer requests. Completion is transactional and appends to the ownership ledger.">
        <x-slot:actions>
            @can('create', App\Models\TransferRequest::class)
                <x-ui.button size="sm" wire:click="$toggle('showCreate')">New transfer</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Current owners --}}
    <x-ui.card title="Current owner(s)">
        @if ($owners->isEmpty())
            <p class="text-sm text-(--content-muted)">No ownership ledger yet — it is created when a possession case or transfer is opened.</p>
        @else
            <ul class="space-y-1 text-sm">
                @foreach ($owners as $o)
                    <li>{{ $o->buyer?->fullName() ?? '—' }}
                        <span class="text-(--content-muted)">· {{ $o->buyer?->customer_code }} · {{ rtrim(rtrim(number_format((float) $o->ownership_percentage, 2), '0'), '.') }}%
                        · {{ $o->ownership_type->label() }} @if ($o->is_primary)· primary @endif</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    {{-- Transfer documents — the 3 required-for-approval types (not shown on
         the Booking Documents screen; that screen shows only Booking Form /
         Payment Documents / Registry Documents). --}}
    <x-ui.card title="Transfer Documents" subtitle="Required to approve an ownership-moving transfer.">
        <table class="min-w-full divide-y divide-(--border) text-sm">
            <thead class="text-left text-xs font-semibold uppercase tracking-wider text-(--content-muted)">
                <tr>
                    <th class="py-2 pr-4">Document</th>
                    <th class="py-2 pr-4">Status</th>
                    <th class="py-2 pr-4">File</th>
                    <th class="py-2 pr-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-(--border)">
                @foreach ($transferDocChecklist->items as $item)
                    @php
                        $doc = $transferDocuments->get($item['document_type_id']);
                        $status = \App\Enums\DocumentStatus::from($item['status']);
                    @endphp
                    <tr wire:key="transfer-doc-{{ $item['document_type_id'] }}">
                        <td class="py-2 pr-4 font-medium text-(--content)">{{ $item['name'] }}</td>
                        <td class="py-2 pr-4"><x-ui.badge :variant="$status->color()">{{ $status->label() }}</x-ui.badge></td>
                        <td class="py-2 pr-4 text-(--content-muted)">
                            @if ($doc?->currentVersion)
                                {{ $doc->currentVersion->original_filename }} ({{ $doc->currentVersion->humanSize() }})
                                @can('download', $doc)
                                    <a href="{{ route('documents.download', ['document' => $doc->id, 'version' => $doc->currentVersion->id]) }}"
                                       target="_blank" class="ml-1 text-(--brand-primary) hover:underline">download</a>
                                @endcan
                            @else — @endif
                            @if ($doc?->rejection_reason && $status === \App\Enums\DocumentStatus::Rejected)
                                <div class="text-xs text-red-600">{{ $doc->rejection_reason }}</div>
                            @endif
                        </td>
                        <td class="py-2 pr-4">
                            <div class="flex flex-wrap items-center justify-end gap-1">
                                @can('documents.upload')
                                    <label class="cursor-pointer text-xs text-(--brand-primary) hover:underline">
                                        {{ $doc?->hasFile() ? 'Replace' : 'Upload' }}
                                        <input type="file" class="hidden" wire:model="transferFiles.{{ $item['document_type_id'] }}" />
                                    </label>
                                    <span wire:loading wire:target="transferFiles.{{ $item['document_type_id'] }}" class="text-xs text-(--content-muted)">Uploading…</span>
                                    @error('transferFiles.'.$item['document_type_id']) <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                @endcan
                                @if ($doc && $status === \App\Enums\DocumentStatus::Uploaded)
                                    <x-ui.button size="sm" variant="ghost" wire:click="submitTransferDocumentForReview({{ $doc->id }})">Send to review</x-ui.button>
                                @endif
                                @if ($doc && in_array($status, [\App\Enums\DocumentStatus::Uploaded, \App\Enums\DocumentStatus::UnderReview], true))
                                    @can('verify', $doc)
                                        <x-ui.button size="sm" variant="ghost" wire:click="verifyTransferDocument({{ $doc->id }})" wire:confirm="Verify {{ $item['name'] }}?">Verify</x-ui.button>
                                    @endcan
                                    @can('reject', $doc)
                                        <x-ui.button size="sm" variant="ghost" class="text-red-600" wire:click="openRejectTransferDocument({{ $doc->id }})">Reject</x-ui.button>
                                    @endcan
                                @endif
                                @if ($doc && ! $doc->isProtected())
                                    @can('delete', $doc)
                                        <x-ui.button size="sm" variant="ghost" class="text-red-600" wire:click="deleteTransferDocument({{ $doc->id }})" wire:confirm="Remove this document?">✕</x-ui.button>
                                    @endcan
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($transferDocRejectingId)
            <div class="fixed inset-0 z-50 flex items-center justify-center p-4" wire:key="transfer-doc-reject-dialog">
                <div class="absolute inset-0 bg-slate-900/50" wire:click="$set('transferDocRejectingId', null)"></div>
                <div class="relative w-full max-w-md rounded-xl border border-(--border) bg-(--surface) shadow-xl">
                    <div class="border-b border-(--border) px-5 py-4"><h3 class="text-sm font-semibold">Reject document</h3></div>
                    <form wire:submit="rejectTransferDocument" class="space-y-4 px-5 py-4">
                        <x-ui.textarea label="Reason (required)" wire:model="transferDocRejectReason" rows="2" :error="$errors->first('transferDocRejectReason')" />
                        <div class="flex justify-end gap-2">
                            <x-ui.button type="button" variant="secondary" wire:click="$set('transferDocRejectingId', null)">Cancel</x-ui.button>
                            <x-ui.button type="submit" variant="danger">Reject</x-ui.button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </x-ui.card>

    @if ($showCreate)
        <x-ui.card title="New transfer request">
            <form wire:submit="create" class="space-y-3">
                <x-ui.select label="Transfer type" wire:model.live="transferType" :options="$transferTypes" />

                @if ($transferType === 'plot_transfer')
                    <x-ui.input label="Current plot" value="Plot {{ $booking->plot?->plot_number }}" disabled />
                    <x-ui.select label="New plot" wire:model="newPlotId" placeholder="Select an available plot…"
                        :options="$plots->toArray()"
                        :error="$errors->first('newPlotId')" />
                @else
                    <x-ui.select label="Incoming buyer" wire:model="newBuyerId" placeholder="Select buyer…"
                        :options="$buyers->mapWithKeys(fn ($b) => [$b->id => $b->fullName().' · '.$b->customer_code])->all()"
                        :error="$errors->first('newBuyerId')" />
                @endif

                <x-ui.input label="Reason" wire:model="reason" :error="$errors->first('reason')" />
                <div class="flex gap-2">
                    <x-ui.button size="sm" type="submit">Create</x-ui.button>
                    <x-ui.button size="sm" type="button" variant="secondary" wire:click="$set('showCreate', false)">Cancel</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @forelse ($transfers as $t)
        @php $elig = $eligibilityByTransfer[$t->id]; @endphp
        <x-ui.card :title="$t->request_number">
            <x-slot:actions><x-ui.badge :variant="$t->status->color()">{{ $t->status->label() }}</x-ui.badge></x-slot:actions>

            <dl class="grid gap-3 text-sm sm:grid-cols-4">
                <div><dt class="text-(--content-muted)">Type</dt><dd>{{ $t->transfer_type->label() }}</dd></div>
                @if ($t->transfer_type->value === 'plot_transfer')
                    <div><dt class="text-(--content-muted)">Current plot</dt><dd>Plot {{ $t->plot?->plot_number ?? '—' }}</dd></div>
                    <div><dt class="text-(--content-muted)">New plot</dt><dd>Plot {{ $t->newPlot?->plot_number ?? '—' }}</dd></div>
                @else
                    <div><dt class="text-(--content-muted)">From</dt><dd>{{ $t->currentBuyer?->fullName() ?? '—' }}</dd></div>
                    <div><dt class="text-(--content-muted)">To</dt><dd>{{ $t->newBuyer?->fullName() ?? '—' }}</dd></div>
                @endif
                <div><dt class="text-(--content-muted)">Approved</dt><dd>{{ $t->approved_at?->format('d M Y') ?? '—' }}</dd></div>
            </dl>

            @if (! $t->status->isTerminal())
                <div class="mt-3 rounded-lg bg-(--surface-muted) p-3 text-sm">
                    <p class="font-medium">Approval readiness</p>
                    <ul class="mt-1 space-y-0.5">
                        @foreach ($elig->checks as $c)
                            <li><span class="{{ $c['passed'] ? 'text-emerald-600' : 'text-red-600' }}">{{ $c['passed'] ? '✔' : '✘' }}</span>
                                {{ $c['label'] }}@if ($c['detail'])<span class="text-(--content-muted)"> — {{ $c['detail'] }}</span>@endif</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-3 flex flex-wrap gap-2">
                @can('update', $t)
                    @if ($t->status === TS::Draft)<x-ui.button size="sm" wire:click="submit({{ $t->id }})">Submit</x-ui.button>@endif
                @endcan
                @can('review', $t)
                    @if ($t->status === TS::Submitted)<x-ui.button size="sm" wire:click="startReview({{ $t->id }})">Start review</x-ui.button>@endif
                    @if ($t->status === TS::UnderReview)
                        <x-ui.button size="sm" variant="secondary" wire:click="requestDocuments({{ $t->id }})">Request documents</x-ui.button>
                        <x-ui.button size="sm" variant="ghost" class="text-red-600" wire:click="$set('reviewingId', {{ $t->id }})">Reject…</x-ui.button>
                    @endif
                    @if ($t->status === TS::DocumentsPending)<x-ui.button size="sm" wire:click="backToReview({{ $t->id }})">Documents received</x-ui.button>@endif
                @endcan
                @can('approve', $t)
                    @if ($t->status === TS::UnderReview)<x-ui.button size="sm" wire:click="approve({{ $t->id }})" wire:confirm="Approve this transfer?">Approve</x-ui.button>@endif
                @endcan
                @can('complete', $t)
                    @if ($t->status === TS::Approved)
                        <x-ui.button size="sm" wire:click="complete({{ $t->id }})"
                            wire:confirm="{{ $t->transfer_type->value === 'plot_transfer' ? 'Complete transfer? The booking will move to the new plot.' : 'Complete transfer? Ownership will move to the new buyer.' }}">
                            Complete transfer
                        </x-ui.button>
                    @endif
                @endcan
                @can('update', $t)
                    @unless ($t->status->isTerminal())<x-ui.button size="sm" variant="ghost" class="text-red-600" wire:click="cancelRequest({{ $t->id }})">Cancel</x-ui.button>@endunless
                @endcan
            </div>

            @if ($reviewingId === $t->id)
                <form wire:submit="reject({{ $t->id }})" class="mt-3 space-y-2 rounded-lg border border-(--border) p-3">
                    <x-ui.input label="Rejection reason" wire:model="rejectReason" :error="$errors->first('rejectReason')" />
                    <x-ui.input label="Financial waiver reason (optional — to approve despite dues)" wire:model="waiverReason" />
                    <div class="flex gap-2">
                        <x-ui.button size="sm" type="submit" variant="danger">Reject</x-ui.button>
                        <x-ui.button size="sm" type="button" variant="secondary" wire:click="$set('reviewingId', null)">Close</x-ui.button>
                    </div>
                </form>
            @endif

            @if ($t->rejection_reason)<p class="mt-2 text-xs text-red-600">Rejected: {{ $t->rejection_reason }}</p>@endif
        </x-ui.card>
    @empty
        <x-ui.card><x-ui.empty-state icon="inbox" title="No transfer requests" description="Raise one to change ownership or record a nominee." /></x-ui.card>
    @endforelse
</div>
