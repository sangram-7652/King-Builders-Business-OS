<div class="space-y-6">
    <x-ui.breadcrumb :items="[
        ['label' => 'Bookings', 'url' => route('bookings.index')],
        ['label' => $booking->booking_number, 'url' => route('bookings.show', $booking)],
        ['label' => 'Documents'],
    ]" />

    <x-ui.page-header :title="'Documents — '.$booking->booking_number"
        description="Booking Form, Payment Documents, Registry Documents and the Plot KYC Receipt.">
        <x-slot:actions>
            @can('registry.view')
                <x-ui.button variant="secondary" size="sm" :href="route('registry.booking', $booking)" wire:navigate>Registry</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Booking Form — single-slot upload/replace/version. The reject dialog
         rendered inside this component is shared by the Payment/Registry
         Documents cards below (they call the same underlying verify/reject/
         delete methods, keyed by document id, not by which card they sit in). --}}
    <x-ui.card title="Booking Form">
        <x-documents.checklist :checklist="$checklist" :documents="$documents" :rejecting-id="$rejectingId" component-id="booking" />
    </x-ui.card>

    {{-- Payment Documents — multiple independent files, each its own record. --}}
    <x-ui.card title="Payment Documents" subtitle="Cheque scans, NEFT/UPI receipts, or any other payment proof. Uploading another file never replaces an earlier one.">
        <x-slot:actions>
            @can('documents.upload')
                <label class="cursor-pointer text-sm text-(--brand-primary) hover:underline">
                    Add file
                    <input type="file" class="hidden" wire:model="newDocuments.PAYMENT_PROOF" />
                </label>
                <span wire:loading wire:target="newDocuments.PAYMENT_PROOF" class="ml-2 text-xs text-(--content-muted)">Uploading…</span>
            @endcan
        </x-slot:actions>
        @error('newDocuments.PAYMENT_PROOF') <p class="mb-2 text-xs text-red-600">{{ $message }}</p> @enderror

        @if ($paymentDocuments->isEmpty())
            <x-ui.empty-state icon="inbox" title="No payment documents yet" description="Add a file once a payment proof is available." />
        @else
            <x-documents.multi-list :documents="$paymentDocuments" />
        @endif
    </x-ui.card>

    {{-- Registry Documents — multiple independent files, each its own record. --}}
    <x-ui.card title="Registry Documents" subtitle="Any document related to the registry process. Uploading another file never replaces an earlier one.">
        <x-slot:actions>
            @can('documents.upload')
                <label class="cursor-pointer text-sm text-(--brand-primary) hover:underline">
                    Add file
                    <input type="file" class="hidden" wire:model="newDocuments.REGISTRY_DOC" />
                </label>
                <span wire:loading wire:target="newDocuments.REGISTRY_DOC" class="ml-2 text-xs text-(--content-muted)">Uploading…</span>
            @endcan
        </x-slot:actions>
        @error('newDocuments.REGISTRY_DOC') <p class="mb-2 text-xs text-red-600">{{ $message }}</p> @enderror

        @if ($registryDocuments->isEmpty())
            <x-ui.empty-state icon="inbox" title="No registry documents yet" description="Add a file once a registry document is available." />
        @else
            <x-documents.multi-list :documents="$registryDocuments" />
        @endif
    </x-ui.card>

    {{-- Plot KYC Receipt --}}
    <x-ui.card title="Plot KYC Receipt" subtitle="Registry KYC Part-1 — village / plot / seller / buyer / witness / payment details.">
        <x-slot:actions>
            @can('documents.upload')
                <x-ui.button size="sm" wire:click="openPlotKyc">{{ $plotKycDocument ? 'Regenerate' : 'Generate' }}</x-ui.button>
            @endcan
        </x-slot:actions>

        @if (! $plotKycDocument || $plotKycDocument->versions->isEmpty())
            <x-ui.empty-state icon="inbox" title="Not generated yet" description="Generate the Plot KYC Receipt once the booking's registry KYC details are ready." />
        @else
            <p class="text-xs font-semibold uppercase tracking-wider text-(--content-muted)">Versions</p>
            <ul class="mt-1 space-y-1 text-sm">
                @foreach ($plotKycDocument->versions->sortByDesc('version') as $v)
                    <li>
                        v{{ $v->version }} — {{ $v->original_filename }} ({{ $v->humanSize() }})
                        · {{ $v->uploaded_at?->format('d M Y H:i') }}
                        @can('download', $plotKycDocument)
                            <a href="{{ route('documents.download', ['document' => $plotKycDocument->id, 'version' => $v->id]) }}" target="_blank" class="ml-1 text-(--brand-primary) hover:underline">view / download</a>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($showPlotKyc)
            <form wire:submit="generatePlotKycReceipt" class="mt-4 space-y-4 rounded-lg border border-(--border) p-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-(--content-muted)">Land records</p>
                    <p class="text-xs text-(--content-muted)">Prefilled from the plot when already on file — otherwise fill it in here; it is saved back to the plot.</p>
                    <div class="mt-2 grid gap-4 sm:grid-cols-2">
                        <x-ui.input label="Village name" wire:model="villageName" :error="$errors->first('villageName')" />
                        <x-ui.input label="Gata No." wire:model="gataNumber" :error="$errors->first('gataNumber')" />
                    </div>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-(--content-muted)">Plot Chauhaddi (boundary)</p>
                    <div class="mt-2 grid gap-4 sm:grid-cols-2">
                        <x-ui.input label="East" wire:model="boundaryEast" :error="$errors->first('boundaryEast')" />
                        <x-ui.input label="West" wire:model="boundaryWest" :error="$errors->first('boundaryWest')" />
                        <x-ui.input label="North" wire:model="boundaryNorth" :error="$errors->first('boundaryNorth')" />
                        <x-ui.input label="South" wire:model="boundarySouth" :error="$errors->first('boundarySouth')" />
                    </div>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-(--content-muted)">Seller / Company</p>
                    <p class="text-xs text-(--content-muted)">Prefilled from branding settings when already configured — otherwise fill them in here. Saved back to branding settings, so editing Company Name, Address or Mobile here changes them everywhere else in the app too.</p>
                    <div class="mt-2 grid gap-4 sm:grid-cols-2">
                        <x-ui.input label="Company Name" wire:model="companyName" :error="$errors->first('companyName')" />
                        <x-ui.input label="Director Name" wire:model="directorName" :error="$errors->first('directorName')" />
                        <x-ui.input label="Address" wire:model="companyAddress" :error="$errors->first('companyAddress')" />
                        <x-ui.input label="PAN" wire:model="panNumber" :error="$errors->first('panNumber')" />
                        <x-ui.input label="Mobile" wire:model="companyMobile" :error="$errors->first('companyMobile')" />
                    </div>
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-wider text-(--content-muted)">Buyer / Customer (Registry Buyer)</p>
                    <p class="text-xs text-(--content-muted)">Prefilled from the booking's buyer — edit if the person named in the registry KYC is different (e.g. a spouse or nominee). This never changes the booking's actual buyer record.</p>
                    <div class="mt-2 grid gap-4 sm:grid-cols-2">
                        <x-ui.input label="Name" wire:model="registryBuyerName" :error="$errors->first('registryBuyerName')" />
                        <x-ui.input label="Mobile" wire:model="registryBuyerMobile" :error="$errors->first('registryBuyerMobile')" />
                        <x-ui.input label="Address" wire:model="registryBuyerAddress" :error="$errors->first('registryBuyerAddress')" />
                        <x-ui.input label="PAN" wire:model="registryBuyerPan" :error="$errors->first('registryBuyerPan')" />
                    </div>
                </div>

                <x-ui.input type="number" step="0.01" label="Vikray Muly (declared registry sale value)" wire:model="vikrayMulyAmount"
                    :error="$errors->first('vikrayMulyAmount')" hint="Optional — leave blank if not yet declared." />

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-2">
                        <p class="text-xs font-semibold uppercase tracking-wider text-(--content-muted)">Witness 1 (optional)</p>
                        <x-ui.input label="Name" wire:model="witness1Name" :error="$errors->first('witness1Name')" />
                        <x-ui.input label="Address" wire:model="witness1Address" :error="$errors->first('witness1Address')" />
                        <x-ui.input label="Mobile" wire:model="witness1Mobile" :error="$errors->first('witness1Mobile')" />
                    </div>
                    <div class="space-y-2">
                        <p class="text-xs font-semibold uppercase tracking-wider text-(--content-muted)">Witness 2 (optional)</p>
                        <x-ui.input label="Name" wire:model="witness2Name" :error="$errors->first('witness2Name')" />
                        <x-ui.input label="Address" wire:model="witness2Address" :error="$errors->first('witness2Address')" />
                        <x-ui.input label="Mobile" wire:model="witness2Mobile" :error="$errors->first('witness2Mobile')" />
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showPlotKyc', false)">Cancel</x-ui.button>
                    <x-ui.button type="submit">{{ $plotKycDocument ? 'Regenerate receipt' : 'Generate receipt' }}</x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.card>
</div>
