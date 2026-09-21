<?php

declare(strict_types=1);

namespace App\Livewire\Bookings;

use App\Actions\Documents\DeleteDocumentAction;
use App\Actions\Documents\RejectDocumentAction;
use App\Actions\Documents\SubmitDocumentForReviewAction;
use App\Actions\Documents\UploadDocumentAction;
use App\Actions\Documents\VerifyDocumentAction;
use App\Actions\Transfer\CompleteTransferAction;
use App\Actions\Transfer\CreateTransferRequestAction;
use App\Actions\Transfer\TransferWorkflowAction;
use App\Enums\PlotStatus;
use App\Enums\TransferType;
use App\Exceptions\DomainException;
use App\Livewire\Documents\ManagesDocumentSlots;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Document;
use App\Models\Masters\DocumentType;
use App\Models\Plot;
use App\Models\TransferRequest;
use App\Services\Documents\DocumentChecklistService;
use App\Services\Ownership\PlotOwnershipService;
use App\Services\Transfer\TransferEligibilityService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Booking transfer workflow (M10). Also hosts the three transfer documents
 * (Application / Consent / ID Proof) that {@see TransferEligibilityService}
 * gates `transfer.approve` on — there is deliberately no upload UI for these
 * on the Booking Documents screen (that screen shows only Booking Form /
 * Payment Documents / Registry Documents); they live here instead, where
 * they are actually consumed. Reuses the exact same M9 Document/
 * DocumentVersion/UploadDocumentAction/DocumentPolicy architecture as every
 * other document screen — the method names below are deliberately distinct
 * from the shared `ManagesDocumentSlots` trait's (`reject`/`verify`/…)
 * because this component already has its own transfer-level `reject(int
 * $id)` etc. for a different concept (rejecting a transfer REQUEST, not a
 * document).
 */
#[Layout('components.layouts.app')]
class BookingTransfers extends Component
{
    use WithFileUploads;

    public Booking $booking;

    /** @var array<int, UploadedFile> keyed by document_type_id */
    public array $transferFiles = [];

    public ?int $transferDocRejectingId = null;

    public string $transferDocRejectReason = '';

    public bool $showCreate = false;

    public string $transferType = 'sale_transfer';

    public string $newBuyerId = '';

    public string $newPlotId = '';

    public string $reason = '';

    public ?int $reviewingId = null;

    public string $rejectReason = '';

    public string $waiverReason = '';

    public function mount(Booking $booking): void
    {
        $this->authorize('viewAny', TransferRequest::class);
        $this->authorize('view', $booking);
        abort_unless($booking->isConfirmed(), 404);
        $this->booking = $booking->loadMissing('plot:id,plot_number,project_id,block_id');
    }

    private function find(int $id): TransferRequest
    {
        return $this->booking->transferRequests()->whereKey($id)->firstOrFail();
    }

    private function run(callable $fn, string $ok): void
    {
        try {
            $fn();
            $this->dispatch('toast', message: $ok, variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function create(): void
    {
        $this->authorize('create', TransferRequest::class);
        $type = TransferType::from($this->transferType);
        $this->validate([
            'newBuyerId' => [$type->movesOwnership() ? 'required' : 'nullable', 'integer', 'exists:buyers,id'],
            'newPlotId' => [$type->movesPlot() ? 'required' : 'nullable', 'integer', 'exists:plots,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $this->run(function () use ($type): void {
            app(CreateTransferRequestAction::class)->handle($this->booking, $type, [
                'new_buyer_id' => $this->newBuyerId !== '' ? (int) $this->newBuyerId : null,
                'new_plot_id' => $this->newPlotId !== '' ? (int) $this->newPlotId : null,
                'reason' => $this->reason ?: null,
            ], auth()->user());
            $this->reset('showCreate', 'newBuyerId', 'newPlotId', 'reason');
        }, 'Transfer request created.');
    }

    public function submit(int $id): void
    {
        $t = $this->find($id);
        $this->authorize('update', $t);
        $this->run(fn () => app(TransferWorkflowAction::class)->submit($t, auth()->user()), 'Transfer submitted.');
    }

    public function startReview(int $id): void
    {
        $t = $this->find($id);
        $this->authorize('review', $t);
        $this->run(fn () => app(TransferWorkflowAction::class)->startReview($t, auth()->user()), 'Review started.');
    }

    public function requestDocuments(int $id): void
    {
        $t = $this->find($id);
        $this->authorize('review', $t);
        $this->run(fn () => app(TransferWorkflowAction::class)->requestDocuments($t, auth()->user()), 'Marked documents pending.');
    }

    public function backToReview(int $id): void
    {
        $t = $this->find($id);
        $this->authorize('review', $t);
        $this->run(fn () => app(TransferWorkflowAction::class)->backToReview($t, auth()->user()), 'Back under review.');
    }

    public function approve(int $id): void
    {
        $t = $this->find($id);
        $this->authorize('approve', $t);
        $this->run(function () use ($t): void {
            app(TransferWorkflowAction::class)->approve($t, auth()->user(), ['financial_waiver_reason' => $this->waiverReason ?: null]);
            $this->reset('waiverReason', 'reviewingId');
        }, 'Transfer approved.');
    }

    public function reject(int $id): void
    {
        $t = $this->find($id);
        $this->authorize('review', $t);
        $this->validate(['rejectReason' => ['required', 'string', 'min:3', 'max:255']]);
        $this->run(function () use ($t): void {
            app(TransferWorkflowAction::class)->reject($t, $this->rejectReason, auth()->user());
            $this->reset('rejectReason', 'reviewingId');
        }, 'Transfer rejected.');
    }

    public function complete(int $id): void
    {
        $t = $this->find($id);
        $this->authorize('complete', $t);
        $this->run(fn () => app(CompleteTransferAction::class)->handle($t, auth()->user()), 'Transfer completed — ownership updated.');
    }

    public function cancelRequest(int $id): void
    {
        $t = $this->find($id);
        $this->authorize('update', $t);
        $this->run(fn () => app(TransferWorkflowAction::class)->cancel($t, 'Cancelled from transfer screen', auth()->user()), 'Transfer cancelled.');
    }

    // --- Transfer documents (TRANSFER_APPLICATION / CONSENT / ID_PROOF) --

    /**
     * Same timing guarantee as {@see ManagesDocumentSlots::updatedFiles()}
     * — fires once Livewire's own async upload cycle has finished.
     */
    public function updatedTransferFiles(mixed $value, string $key): void
    {
        if ($value instanceof UploadedFile && ctype_digit($key)) {
            $this->uploadTransferDocument((int) $key);
        }
    }

    public function uploadTransferDocument(int $documentTypeId): void
    {
        $file = $this->transferFiles[$documentTypeId] ?? null;
        $this->validate([
            'transferFiles.'.$documentTypeId => ['required', 'file', 'max:'.config('registry.uploads.max_kb'), 'mimes:'.implode(',', config('registry.uploads.mimes'))],
        ], [], ['transferFiles.'.$documentTypeId => 'file']);

        $type = DocumentType::findOrFail($documentTypeId);

        $probe = Document::firstOrNew([
            'documentable_type' => $this->booking->getMorphClass(),
            'documentable_id' => $this->booking->getKey(),
            'document_type_id' => $type->id,
        ]);
        $probe->setRelation('documentable', $this->booking);
        $this->authorize('upload', $probe->exists ? $probe : $probe->fill(['status' => 'pending']));

        try {
            app(UploadDocumentAction::class)->handle($this->booking, $type, $file, auth()->user());
            unset($this->transferFiles[$documentTypeId]);
            $this->dispatch('toast', message: "{$type->name} uploaded.", variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function submitTransferDocumentForReview(int $documentId): void
    {
        $document = $this->scopedTransferDocument($documentId);
        $this->authorize('view', $document);

        try {
            app(SubmitDocumentForReviewAction::class)->handle($document, auth()->user());
            $this->dispatch('toast', message: 'Sent for review.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function verifyTransferDocument(int $documentId): void
    {
        $document = $this->scopedTransferDocument($documentId);
        $this->authorize('verify', $document);

        try {
            app(VerifyDocumentAction::class)->handle($document, auth()->user());
            $this->dispatch('toast', message: 'Document verified.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function openRejectTransferDocument(int $documentId): void
    {
        $this->transferDocRejectingId = $documentId;
        $this->transferDocRejectReason = '';
    }

    public function rejectTransferDocument(): void
    {
        $document = $this->scopedTransferDocument($this->transferDocRejectingId);
        $this->authorize('reject', $document);
        $this->validate(['transferDocRejectReason' => ['required', 'string', 'min:3', 'max:255']]);

        try {
            app(RejectDocumentAction::class)->handle($document, $this->transferDocRejectReason, auth()->user());
            $this->reset('transferDocRejectingId', 'transferDocRejectReason');
            $this->dispatch('toast', message: 'Document rejected.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function deleteTransferDocument(int $documentId): void
    {
        $document = $this->scopedTransferDocument($documentId);
        $this->authorize('delete', $document);

        try {
            app(DeleteDocumentAction::class)->handle($document, auth()->user());
            $this->dispatch('toast', message: 'Document removed.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    private function scopedTransferDocument(int $documentId): Document
    {
        return Document::query()
            ->where('documentable_type', $this->booking->getMorphClass())
            ->where('documentable_id', $this->booking->id)
            ->findOrFail($documentId);
    }

    public function render(): View
    {
        $transfers = $this->booking->transferRequests()
            ->with([
                'currentBuyer:id,first_name,middle_name,last_name,customer_code', 'newBuyer:id,first_name,middle_name,last_name,customer_code',
                'plot:id,plot_number', 'newPlot:id,plot_number', 'approvedBy:id,name',
            ])
            ->get();

        $eligibilityByTransfer = $transfers->mapWithKeys(fn (TransferRequest $t) => [
            $t->id => app(TransferEligibilityService::class)->evaluate($t),
        ]);

        $owners = app(PlotOwnershipService::class)->currentOwners($this->booking);

        // Plot-transfer target picker: active, available/held plots in the
        // SAME project as the booking's current plot, excluding it.
        $plots = Plot::query()
            ->where('project_id', $this->booking->project_id)
            ->where('is_active', true)
            ->whereIn('status', [PlotStatus::Available->value, PlotStatus::Hold->value])
            ->whereKeyNot($this->booking->plot_id)
            ->orderBy('plot_number')
            ->get(['id', 'plot_number', 'block_id']);

        $transferDocCodes = ['TRANSFER_APPLICATION', 'TRANSFER_CONSENT', 'TRANSFER_ID_PROOF'];
        $transferDocChecklist = app(DocumentChecklistService::class)->forBooking($this->booking, $transferDocCodes);
        $transferDocuments = Document::query()
            ->where('documentable_type', $this->booking->getMorphClass())
            ->where('documentable_id', $this->booking->id)
            ->whereHas('documentType', fn ($q) => $q->whereIn('code', $transferDocCodes))
            ->with(['currentVersion'])
            ->get()
            ->keyBy('document_type_id');

        return view('livewire.bookings.booking-transfers', [
            'booking' => $this->booking,
            'transfers' => $transfers,
            'eligibilityByTransfer' => $eligibilityByTransfer,
            'owners' => $owners->load('buyer:id,first_name,middle_name,last_name,customer_code'),
            'transferTypes' => TransferType::options(),
            'buyers' => Buyer::query()->where('status', 'active')->orderBy('first_name')->get(['id', 'first_name', 'middle_name', 'last_name', 'customer_code']),
            'plots' => $plots->mapWithKeys(fn (Plot $p) => [$p->id => "Plot {$p->plot_number}"]),
            'transferDocChecklist' => $transferDocChecklist,
            'transferDocuments' => $transferDocuments,
        ])->title("Transfers · {$this->booking->booking_number}");
    }
}
