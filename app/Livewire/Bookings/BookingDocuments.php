<?php

declare(strict_types=1);

namespace App\Livewire\Bookings;

use App\Actions\Bookings\GeneratePlotKycReceiptAction;
use App\Actions\Documents\AddDocumentAction;
use App\Exceptions\DomainException;
use App\Livewire\Documents\ManagesDocumentSlots;
use App\Models\Booking;
use App\Models\Document;
use App\Models\Masters\DocumentType;
use App\Services\Documents\DocumentChecklistService;
use App\Support\Bookings\BookingCancellationGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Booking Documents (M9). Shows exactly four document categories — Booking
 * Form (single-slot: upload/replace/version, via {@see ManagesDocumentSlots}),
 * Payment Documents and Registry Documents (multi-file: every upload stays
 * its own independent {@see Document} row, via {@see AddDocumentAction}), and
 * the generated Plot KYC Receipt. Every other Booking-scope document type
 * (registry/possession/transfer artefacts generated or uploaded by their own
 * dedicated workflow screens) is intentionally not listed here; hiding a type
 * from this screen never touches its data or its own upload path elsewhere.
 *
 * The dedicated Booking Agreement workflow (create/prepare/send/record
 * signed/approve/cancel) has been removed from this screen entirely — a
 * client product decision. `App\Models\Agreement`, its migration/table, the
 * `AgreementStatus`/`AgreementType` enums and `Booking::agreement()`/
 * `agreements()` are all kept intact: historical Agreement rows stay fully
 * readable (portal booking page, the documents dashboard) and two OTHER
 * modules still read them — {@see BookingCancellationGuard}
 * (blocks cancelling a booking with a signed agreement) and
 * `RegistryEligibilityService` (its `require_agreement_signed` config gate is
 * now off, since no code path can create/sign a NEW agreement any more).
 */
#[Layout('components.layouts.app')]
class BookingDocuments extends Component
{
    use ManagesDocumentSlots;

    public Booking $booking;

    // --- Plot KYC Receipt ------------------------------------------

    public bool $showPlotKyc = false;

    public string $vikrayMulyAmount = '';

    public string $witness1Name = '';

    public string $witness1Address = '';

    public string $witness1Mobile = '';

    public string $witness2Name = '';

    public string $witness2Address = '';

    public string $witness2Mobile = '';

    // Land records — same plots.village_name/gata_number/boundary_* columns
    // the Plot form writes to; prefilled from the Plot when present, blank
    // and editable otherwise (see openPlotKyc()).
    public string $villageName = '';

    public string $gataNumber = '';

    public string $boundaryEast = '';

    public string $boundaryWest = '';

    public string $boundaryNorth = '';

    public string $boundarySouth = '';

    // Seller / Company — all five fields, prefilled from config/branding.php
    // when present, blank and editable otherwise (see openPlotKyc()).
    // Persisted back to the SAME branding config source (.env) — see
    // GeneratePlotKycReceiptAction::syncSellerConfig(). Editing Company
    // Name/Address/Mobile here changes them everywhere else in the app too,
    // since branding config is tenant-wide, not per-document.
    public string $companyName = '';

    public string $directorName = '';

    public string $companyAddress = '';

    public string $panNumber = '';

    public string $companyMobile = '';

    // Registry Buyer — the name/mobile/address/PAN shown on THIS receipt,
    // which may differ from the booking's actual buyer (e.g. a spouse or
    // nominee). Prefilled from a previously-saved registry_buyer_* value
    // when present, otherwise from the booking's primary buyer — editable
    // either way. Persisted to bookings.registry_buyer_* only; the actual
    // booking_buyers / Buyer record is never touched (see
    // GeneratePlotKycReceiptAction::syncRegistryBuyer()).
    public string $registryBuyerName = '';

    public string $registryBuyerMobile = '';

    public string $registryBuyerAddress = '';

    public string $registryBuyerPan = '';

    // --- Multi-file categories (Payment Documents / Registry Documents) --

    /** @var array<string, UploadedFile> keyed by document type code */
    public array $newDocuments = [];

    public function mount(Booking $booking): void
    {
        $this->authorize('view', $booking);
        abort_unless(auth()->user()->can('documents.view'), 403);
        abort_unless($booking->isConfirmed(), 404);
        $this->booking = $booking;
    }

    protected function documentable(): Model
    {
        return $this->booking;
    }

    // --- Payment Documents / Registry Documents (multi-file) ---------

    /**
     * Fires once Livewire finishes the file's own async upload cycle and
     * sets `newDocuments.{$key}` — same timing guarantee documented on
     * {@see ManagesDocumentSlots::updatedFiles()}, keyed by document type
     * code instead of id since a code (not an id) selects which of the two
     * multi-file categories this upload belongs to.
     */
    public function updatedNewDocuments(mixed $value, string $key): void
    {
        if ($value instanceof UploadedFile) {
            $this->addDocument($key);
        }
    }

    public function addDocument(string $code): void
    {
        $file = $this->newDocuments[$code] ?? null;

        if (! $file instanceof UploadedFile) {
            return;
        }

        $this->validate([
            'newDocuments.'.$code => ['required', 'file', 'max:'.config('registry.uploads.max_kb'), 'mimes:'.implode(',', config('registry.uploads.mimes'))],
        ], [], ['newDocuments.'.$code => 'file']);

        $type = DocumentType::query()->where('code', $code)->where('allows_multiple', true)->firstOrFail();
        $booking = $this->booking;

        $probe = Document::firstOrNew([
            'documentable_type' => $booking->getMorphClass(),
            'documentable_id' => $booking->getKey(),
            'document_type_id' => $type->id,
        ]);
        $probe->setRelation('documentable', $booking);
        $this->authorize('upload', $probe->exists ? $probe : $probe->fill(['status' => 'pending']));

        try {
            app(AddDocumentAction::class)->handle($booking, $type, $file, auth()->user());
            unset($this->newDocuments[$code]);
            $this->dispatch('toast', message: "{$type->name} added.", variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    /** @return Collection<int, Document> */
    private function multiDocuments(string $code): Collection
    {
        return Document::query()
            ->where('documentable_type', $this->booking->getMorphClass())
            ->where('documentable_id', $this->booking->id)
            ->whereHas('documentType', fn ($q) => $q->where('code', $code))
            ->with(['currentVersion'])
            ->orderBy('sequence')
            ->get();
    }

    // --- Plot KYC Receipt --------------------------------------------

    public function openPlotKyc(): void
    {
        $this->booking->loadMissing(['witnesses', 'plot', 'bookingBuyers.buyer']);

        $this->vikrayMulyAmount = $this->booking->vikray_muly_amount !== null
            ? (string) $this->booking->vikray_muly_amount
            : '';

        $byNumber = $this->booking->witnesses->keyBy('witness_number');
        $this->witness1Name = (string) ($byNumber->get(1)?->name ?? '');
        $this->witness1Address = (string) ($byNumber->get(1)?->address ?? '');
        $this->witness1Mobile = (string) ($byNumber->get(1)?->mobile ?? '');
        $this->witness2Name = (string) ($byNumber->get(2)?->name ?? '');
        $this->witness2Address = (string) ($byNumber->get(2)?->address ?? '');
        $this->witness2Mobile = (string) ($byNumber->get(2)?->mobile ?? '');

        // Prefill from the Plot's own land-record columns when present —
        // blank (editable) otherwise. Nothing forces the operator to leave
        // this page to fix the Plot first.
        $plot = $this->booking->plot;
        $this->villageName = (string) ($plot?->village_name ?? '');
        $this->gataNumber = (string) ($plot?->gata_number ?? '');
        $this->boundaryEast = (string) ($plot?->boundary_east ?? '');
        $this->boundaryWest = (string) ($plot?->boundary_west ?? '');
        $this->boundaryNorth = (string) ($plot?->boundary_north ?? '');
        $this->boundarySouth = (string) ($plot?->boundary_south ?? '');

        // Seller / Company — all five, prefilled from Branding config when
        // present, blank (editable) otherwise.
        $this->companyName = (string) (config('branding.name') ?? '');
        $this->directorName = (string) (config('branding.contact.director_name') ?? '');
        $this->companyAddress = (string) (config('branding.contact.head_office_address') ?? '');
        $this->panNumber = (string) (config('branding.contact.pan_number') ?? '');
        $this->companyMobile = (string) (config('branding.contact.phone') ?? '');

        // Registry Buyer — prefilled from a previously-saved value when
        // present, otherwise from the booking's primary buyer (falling back
        // to the first buyer if none is flagged primary).
        $primaryBuyer = $this->booking->bookingBuyers->firstWhere('is_primary', true)
            ?? $this->booking->bookingBuyers->first();
        $buyer = $primaryBuyer?->buyer;

        $this->registryBuyerName = (string) ($this->booking->registry_buyer_name ?: ($buyer?->fullName() ?? ''));
        $this->registryBuyerMobile = (string) ($this->booking->registry_buyer_mobile ?: ($buyer?->phone ?? ''));
        $this->registryBuyerAddress = (string) ($this->booking->registry_buyer_address ?: ($buyer?->address ?? ''));
        $this->registryBuyerPan = (string) ($this->booking->registry_buyer_pan ?: ($buyer?->pan_number ?? ''));

        $this->showPlotKyc = true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function plotKycRules(): array
    {
        $mobile = ['nullable', 'string', 'max:20', 'regex:/^[0-9+()\-\s]{6,20}$/'];
        $pan = ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z]{5}[0-9]{4}[A-Za-z]$/'];
        // No $ / { } / control characters — these are the exact
        // metacharacters phpdotenv treats specially when it re-reads .env
        // (see BrandingConfigWriter), so rejecting them here stops an
        // env-interpolation payload from ever reaching that file. Only
        // applies to fields that flow into branding config — the Registry
        // Buyer fields below persist to the `bookings` table, not `.env`.
        $envSafeText = 'regex:/^[^$\{\}\x00-\x1F\x7F]*$/';

        return [
            'vikrayMulyAmount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'witness1Name' => ['nullable', 'string', 'max:255'],
            'witness1Address' => ['nullable', 'string', 'max:255'],
            'witness1Mobile' => $mobile,
            'witness2Name' => ['nullable', 'string', 'max:255'],
            'witness2Address' => ['nullable', 'string', 'max:255'],
            'witness2Mobile' => $mobile,
            'villageName' => ['nullable', 'string', 'max:255'],
            'gataNumber' => ['nullable', 'string', 'max:64'],
            'boundaryEast' => ['nullable', 'string', 'max:255'],
            'boundaryWest' => ['nullable', 'string', 'max:255'],
            'boundaryNorth' => ['nullable', 'string', 'max:255'],
            'boundarySouth' => ['nullable', 'string', 'max:255'],
            'companyName' => ['nullable', 'string', 'max:255', $envSafeText],
            'directorName' => ['nullable', 'string', 'max:255', $envSafeText],
            'companyAddress' => ['nullable', 'string', 'max:500', $envSafeText],
            'panNumber' => $pan,
            'companyMobile' => $mobile,
            'registryBuyerName' => ['nullable', 'string', 'max:255'],
            'registryBuyerMobile' => $mobile,
            'registryBuyerAddress' => ['nullable', 'string', 'max:255'],
            'registryBuyerPan' => $pan,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'companyName.regex' => 'Company Name cannot contain $, { }, or control characters.',
            'directorName.regex' => 'Director Name cannot contain $, { }, or control characters.',
            'companyAddress.regex' => 'Address cannot contain $, { }, or control characters.',
            'panNumber.regex' => 'Enter a valid PAN (AAAAA9999A).',
            'registryBuyerPan.regex' => 'Enter a valid PAN (AAAAA9999A).',
        ];
    }

    public function generatePlotKycReceipt(): void
    {
        $booking = $this->booking;

        $probe = Document::firstOrNew([
            'documentable_type' => $booking->getMorphClass(),
            'documentable_id' => $booking->getKey(),
            'document_type_id' => DocumentType::query()->where('code', 'PLOT_KYC_RECEIPT')->value('id'),
        ]);
        $probe->setRelation('documentable', $booking);
        $this->authorize('upload', $probe->exists ? $probe : $probe->fill(['status' => 'pending']));

        $data = $this->validate($this->plotKycRules());

        try {
            app(GeneratePlotKycReceiptAction::class)->handle($booking, auth()->user(), [
                'vikray_muly_amount' => $data['vikrayMulyAmount'] !== '' ? $data['vikrayMulyAmount'] : null,
                'witnesses' => [
                    ['name' => $data['witness1Name'], 'address' => $data['witness1Address'], 'mobile' => $data['witness1Mobile']],
                    ['name' => $data['witness2Name'], 'address' => $data['witness2Address'], 'mobile' => $data['witness2Mobile']],
                ],
                'plot' => [
                    'village_name' => $data['villageName'],
                    'gata_number' => $data['gataNumber'],
                    'boundary_east' => $data['boundaryEast'],
                    'boundary_west' => $data['boundaryWest'],
                    'boundary_north' => $data['boundaryNorth'],
                    'boundary_south' => $data['boundarySouth'],
                ],
                'seller' => [
                    'company_name' => $data['companyName'],
                    'director_name' => $data['directorName'],
                    'address' => $data['companyAddress'],
                    'pan_number' => $data['panNumber'],
                    'mobile' => $data['companyMobile'],
                ],
                'registry_buyer' => [
                    'name' => $data['registryBuyerName'],
                    'mobile' => $data['registryBuyerMobile'],
                    'address' => $data['registryBuyerAddress'],
                    'pan' => $data['registryBuyerPan'],
                ],
            ]);

            $this->showPlotKyc = false;
            $this->dispatch('toast', message: 'Plot KYC Receipt generated.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function render(): View
    {
        $booking = $this->booking;

        // This screen shows exactly one checklist row — Booking Form. Every
        // other Booking-scope type either has its own dedicated card below
        // (Plot KYC Receipt) or its own dedicated screen entirely (Registry,
        // Possession, Transfers) — see the class docblock.
        $checklist = app(DocumentChecklistService::class)->forBooking($booking, ['BOOKING_FORM']);

        $documents = Document::query()
            ->where('documentable_type', $booking->getMorphClass())
            ->where('documentable_id', $booking->id)
            ->with(['documentType', 'currentVersion', 'verifiedBy', 'versions'])
            ->get()
            ->keyBy('document_type_id');

        $plotKycType = DocumentType::query()->where('code', 'PLOT_KYC_RECEIPT')->first();
        $plotKycDocument = $plotKycType !== null ? $documents->get($plotKycType->id) : null;

        return view('livewire.bookings.booking-documents', [
            'booking' => $booking,
            'checklist' => $checklist,
            'documents' => $documents,
            'plotKycDocument' => $plotKycDocument,
            'paymentDocuments' => $this->multiDocuments('PAYMENT_PROOF'),
            'registryDocuments' => $this->multiDocuments('REGISTRY_DOC'),
        ])->title("Documents · {$booking->booking_number}");
    }
}
