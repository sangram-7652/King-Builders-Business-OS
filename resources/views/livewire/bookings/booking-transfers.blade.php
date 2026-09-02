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

    @if ($showCreate)
        <x-ui.card title="New transfer request">
            <form wire:submit="create" class="space-y-3">
                <x-ui.select label="Transfer type" wire:model.live="transferType" :options="$transferTypes" />
                <x-ui.select label="Incoming buyer" wire:model="newBuyerId" placeholder="Select buyer…"
                    :options="$buyers->mapWithKeys(fn ($b) => [$b->id => $b->fullName().' · '.$b->customer_code])->all()"
                    :error="$errors->first('newBuyerId')" />
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
                <div><dt class="text-(--content-muted)">From</dt><dd>{{ $t->currentBuyer?->fullName() ?? '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">To</dt><dd>{{ $t->newBuyer?->fullName() ?? '—' }}</dd></div>
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
                    @if ($t->status === TS::Approved)<x-ui.button size="sm" wire:click="complete({{ $t->id }})" wire:confirm="Complete transfer? Ownership will move to the new buyer.">Complete transfer</x-ui.button>@endif
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
