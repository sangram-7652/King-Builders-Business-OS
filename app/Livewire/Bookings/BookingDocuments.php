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

    // Seller / Company — Director Name and PAN are the only two fields with
    // no other source; prefilled from config/branding.php when present,
    // blank and editable otherwise (see openPlotKyc()). Company Name,
    // Address and Mobile stay exactly as they are today (read straight from
    // Branding in the Blade — no form field, never editable here).
    public string $directorName = '';

    public string $panNumber = '';

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
        $this->booking->loadMissing(['witnesses', 'plot']);

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

        // Seller / Company — Director Name and PAN, prefilled from Branding
        // config when present, blank (editable) otherwise. Company Name /
        // Address / Mobile are shown read-only in the Blade straight from
        // Branding — no property needed for them.
        $this->directorName = (string) (config('branding.contact.director_name') ?? '');
        $this->panNumber = (string) (config('branding.contact.pan_number') ?? '');

        $this->showPlotKyc = true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function plotKycRules(): array
    {
        $mobile = ['nullable', 'string', 'max:20', 'regex:/^[0-9+()\-\s]{6,20}$/'];

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
            'directorName' => ['nullable', 'string', 'max:255'],
            'panNumber' => ['nullable', 'string', 'max:20'],
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
                    'director_name' => $data['directorName'],
                    'pan_number' => $data['panNumber'],
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
