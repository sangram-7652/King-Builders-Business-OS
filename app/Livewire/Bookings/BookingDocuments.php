<?php

declare(strict_types=1);

namespace App\Livewire\Bookings;

use App\Actions\Agreements\CreateAgreementAction;
use App\Actions\Agreements\PrepareAgreementAction;
use App\Actions\Agreements\TransitionAgreementAction;
use App\Actions\Bookings\GeneratePlotKycReceiptAction;
use App\Enums\AgreementType;
use App\Exceptions\DomainException;
use App\Livewire\Documents\ManagesDocumentSlots;
use App\Models\Agreement;
use App\Models\Booking;
use App\Models\Document;
use App\Models\Masters\DocumentType;
use App\Services\Documents\DocumentChecklistService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class BookingDocuments extends Component
{
    use ManagesDocumentSlots;

    public Booking $booking;

    public bool $showSign = false;

    public string $signedBy = '';

    /** @var UploadedFile|null */
    public $signedFile = null;

    // --- Plot KYC Receipt ------------------------------------------

    public bool $showPlotKyc = false;

    public string $vikrayMulyAmount = '';

    public string $witness1Name = '';

    public string $witness1Address = '';

    public string $witness1Mobile = '';

    public string $witness2Name = '';

    public string $witness2Address = '';

    public string $witness2Mobile = '';

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

    // --- Agreement -----------------------------------------------

    public function createAgreement(): void
    {
        $this->authorize('create', Agreement::class);

        try {
            app(CreateAgreementAction::class)->handle($this->booking, AgreementType::BookingAgreement, auth()->user());
            $this->dispatch('toast', message: 'Agreement created.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function prepareAgreement(): void
    {
        $agreement = $this->booking->agreement()->firstOrFail();
        $this->authorize('update', $agreement);

        try {
            app(PrepareAgreementAction::class)->handle($agreement, auth()->user());
            $this->dispatch('toast', message: 'Agreement prepared.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function sendAgreement(): void
    {
        $agreement = $this->booking->agreement()->firstOrFail();
        $this->authorize('update', $agreement);

        try {
            app(TransitionAgreementAction::class)->send($agreement, auth()->user());
            $this->dispatch('toast', message: 'Agreement marked as sent.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function signAgreement(): void
    {
        $agreement = $this->booking->agreement()->firstOrFail();
        $this->authorize('update', $agreement);
        $this->validate([
            'signedBy' => ['required', 'string', 'max:255'],
            'signedFile' => ['required', 'file', 'max:'.config('registry.uploads.max_kb'), 'mimes:'.implode(',', config('registry.uploads.mimes'))],
        ]);

        try {
            app(TransitionAgreementAction::class)->sign($agreement, $this->signedBy, $this->signedFile, auth()->user());
            $this->reset('showSign', 'signedBy', 'signedFile');
            $this->dispatch('toast', message: 'Signed agreement recorded.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function approveAgreement(): void
    {
        $agreement = $this->booking->agreement()->firstOrFail();
        $this->authorize('approve', $agreement);

        try {
            app(TransitionAgreementAction::class)->approve($agreement, auth()->user());
            $this->dispatch('toast', message: 'Agreement approved.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function cancelAgreement(): void
    {
        $agreement = $this->booking->agreement()->firstOrFail();
        $this->authorize('cancel', $agreement);

        try {
            app(TransitionAgreementAction::class)->cancel($agreement, auth()->user(), 'Cancelled from booking documents');
            $this->dispatch('toast', message: 'Agreement cancelled.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    // --- Plot KYC Receipt --------------------------------------------

    public function openPlotKyc(): void
    {
        $this->booking->loadMissing('witnesses');

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
        $checklist = app(DocumentChecklistService::class)->forBooking($booking);

        $documents = Document::query()
            ->where('documentable_type', $booking->getMorphClass())
            ->where('documentable_id', $booking->id)
            ->with(['documentType', 'currentVersion', 'verifiedBy', 'versions'])
            ->get()
            ->keyBy('document_type_id');

        $agreement = $booking->agreement()->with(['document.versions', 'preparedBy', 'approvedBy'])->first();

        $plotKycType = DocumentType::query()->where('code', 'PLOT_KYC_RECEIPT')->first();
        $plotKycDocument = $plotKycType !== null ? $documents->get($plotKycType->id) : null;

        return view('livewire.bookings.booking-documents', [
            'booking' => $booking,
            'checklist' => $checklist,
            'documents' => $documents,
            'agreement' => $agreement,
            'plotKycDocument' => $plotKycDocument,
        ])->title("Documents · {$booking->booking_number}");
    }
}
