<?php

declare(strict_types=1);

namespace App\Livewire\Bookings;

use App\Actions\Agreements\CreateAgreementAction;
use App\Actions\Agreements\PrepareAgreementAction;
use App\Actions\Agreements\TransitionAgreementAction;
use App\Enums\AgreementType;
use App\Exceptions\DomainException;
use App\Livewire\Documents\ManagesDocumentSlots;
use App\Models\Agreement;
use App\Models\Booking;
use App\Models\Document;
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

        return view('livewire.bookings.booking-documents', [
            'booking' => $booking,
            'checklist' => $checklist,
            'documents' => $documents,
            'agreement' => $agreement,
        ])->title("Documents · {$booking->booking_number}");
    }
}
