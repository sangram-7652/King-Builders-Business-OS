@php use App\Enums\AgreementStatus; @endphp
<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Bookings', 'url' => route('bookings.index')],
        ['label' => $booking->booking_number, 'url' => route('bookings.show', $booking)],
        ['label' => 'Documents'],
    ]" />

    <x-ui.page-header :title="'Documents — '.$booking->booking_number"
        description="Booking document checklist and the agreement workflow.">
        <x-slot:actions>
            @can('registry.view')
                <x-ui.button variant="secondary" size="sm" :href="route('registry.booking', $booking)" wire:navigate>Registry</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card title="Document checklist">
        <x-documents.checklist :checklist="$checklist" :documents="$documents" :rejecting-id="$rejectingId" component-id="booking" />
    </x-ui.card>

    {{-- Agreement --}}
    <x-ui.card title="Agreement">
        <x-slot:actions>
            @if (! $agreement)
                @can('create', App\Models\Agreement::class)
                    <x-ui.button size="sm" wire:click="createAgreement">Create agreement</x-ui.button>
                @endcan
            @else
                <x-ui.badge :variant="$agreement->status->color()">{{ $agreement->status->label() }}</x-ui.badge>
            @endif
        </x-slot:actions>

        @if (! $agreement)
            <x-ui.empty-state icon="inbox" title="No agreement yet" description="Create the agreement to begin preparation." />
        @else
            <dl class="grid gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-(--content-muted)">Number</dt><dd class="mt-0.5">{{ $agreement->agreement_number }}</dd></div>
                <div><dt class="text-(--content-muted)">Type</dt><dd class="mt-0.5">{{ $agreement->type->label() }}</dd></div>
                <div><dt class="text-(--content-muted)">Prepared</dt><dd class="mt-0.5">{{ $agreement->prepared_at?->format('d M Y H:i') ?? '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Sent</dt><dd class="mt-0.5">{{ $agreement->sent_at?->format('d M Y') ?? '—' }}</dd></div>
                <div><dt class="text-(--content-muted)">Signed</dt><dd class="mt-0.5">{{ $agreement->signed_at?->format('d M Y') ?? '—' }} {{ $agreement->signed_by ? '· '.$agreement->signed_by : '' }}</dd></div>
                <div><dt class="text-(--content-muted)">Approved</dt><dd class="mt-0.5">{{ $agreement->approved_at?->format('d M Y') ?? '—' }}</dd></div>
            </dl>

            @if ($agreement->document && $agreement->document->versions->isNotEmpty())
                <div class="mt-4">
                    <p class="text-xs font-semibold uppercase tracking-wider text-(--content-muted)">Versions</p>
                    <ul class="mt-1 space-y-1 text-sm">
                        @foreach ($agreement->document->versions->sortByDesc('version') as $v)
                            <li>
                                v{{ $v->version }} — {{ $v->original_filename }} ({{ $v->humanSize() }})
                                @can('download', $agreement->document)
                                    <a href="{{ route('documents.download', ['document' => $agreement->document->id, 'version' => $v->id]) }}" target="_blank" class="ml-1 text-(--brand-primary) hover:underline">download</a>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="mt-4 flex flex-wrap gap-2">
                @can('update', $agreement)
                    @if (in_array($agreement->status, [AgreementStatus::Draft, AgreementStatus::Prepared], true))
                        <x-ui.button size="sm" wire:click="prepareAgreement">{{ $agreement->status === AgreementStatus::Draft ? 'Prepare' : 'Re-prepare' }}</x-ui.button>
                    @endif
                    @if ($agreement->status === AgreementStatus::Prepared)
                        <x-ui.button size="sm" variant="secondary" wire:click="sendAgreement">Mark sent</x-ui.button>
                    @endif
                    @if (in_array($agreement->status, [AgreementStatus::Prepared, AgreementStatus::Sent], true))
                        <x-ui.button size="sm" wire:click="$toggle('showSign')">Record signed</x-ui.button>
                    @endif
                @endcan
                @can('approve', $agreement)
                    <x-ui.button size="sm" wire:click="approveAgreement" wire:confirm="Approve this agreement? It becomes final.">Approve</x-ui.button>
                @endcan
                @can('cancel', $agreement)
                    <x-ui.button size="sm" variant="ghost" class="text-red-600" wire:click="cancelAgreement" wire:confirm="Cancel this agreement?">Cancel</x-ui.button>
                @endcan
            </div>

            @if ($showSign)
                <form wire:submit="signAgreement" class="mt-4 space-y-3 rounded-lg border border-(--border) p-4">
                    <x-ui.input label="Signed by (party name)" wire:model="signedBy" :error="$errors->first('signedBy')" />
                    <div>
                        <label class="text-sm">Signed scan (PDF / image)</label>
                        <input type="file" wire:model="signedFile" class="mt-1 block text-sm" />
                        @error('signedFile') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="secondary" wire:click="$set('showSign', false)">Cancel</x-ui.button>
                        <x-ui.button type="submit">Record signed</x-ui.button>
                    </div>
                </form>
            @endif
        @endif
    </x-ui.card>
</div>
