<?php

declare(strict_types=1);

namespace App\Livewire\Documents;

use App\Actions\Documents\DeleteDocumentAction;
use App\Actions\Documents\RejectDocumentAction;
use App\Actions\Documents\SubmitDocumentForReviewAction;
use App\Actions\Documents\UploadDocumentAction;
use App\Actions\Documents\VerifyDocumentAction;
use App\Exceptions\DomainException;
use App\Models\Document;
use App\Models\Masters\DocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Livewire\WithFileUploads;

/**
 * Shared document-slot behaviour for the buyer / booking document Livewire
 * pages (M9): upload, submit for review, verify, reject, delete. The concrete
 * component supplies the documentable via {@see documentable()}.
 */
trait ManagesDocumentSlots
{
    use WithFileUploads;

    /** @var array<int, UploadedFile> keyed by document_type_id */
    public array $files = [];

    public ?int $rejectingId = null;

    public string $rejectReason = '';

    abstract protected function documentable(): Model;

    protected function uploadRules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.config('registry.uploads.max_kb'), 'mimes:'.implode(',', config('registry.uploads.mimes'))],
        ];
    }

    public function upload(int $documentTypeId): void
    {
        $file = $this->files[$documentTypeId] ?? null;
        $this->validate(['files.'.$documentTypeId => $this->uploadRules()['file']], [], ['files.'.$documentTypeId => 'file']);

        $type = DocumentType::findOrFail($documentTypeId);
        $documentable = $this->documentable();

        // A user who can upload for this documentable at all.
        $probe = Document::firstOrNew([
            'documentable_type' => $documentable->getMorphClass(),
            'documentable_id' => $documentable->getKey(),
            'document_type_id' => $type->id,
        ]);
        $probe->setRelation('documentable', $documentable);
        $this->authorize('upload', $probe->exists ? $probe : $probe->fill(['status' => 'pending']));

        try {
            app(UploadDocumentAction::class)->handle($documentable, $type, $file, auth()->user());
            unset($this->files[$documentTypeId]);
            $this->dispatch('toast', message: "{$type->name} uploaded.", variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function submitForReview(int $documentId): void
    {
        $document = $this->scopedDocument($documentId);
        $this->authorize('view', $document);

        try {
            app(SubmitDocumentForReviewAction::class)->handle($document, auth()->user());
            $this->dispatch('toast', message: 'Sent for review.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function verify(int $documentId): void
    {
        $document = $this->scopedDocument($documentId);
        $this->authorize('verify', $document);

        try {
            app(VerifyDocumentAction::class)->handle($document, auth()->user());
            $this->dispatch('toast', message: 'Document verified.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function openReject(int $documentId): void
    {
        $this->rejectingId = $documentId;
        $this->rejectReason = '';
    }

    public function reject(): void
    {
        $document = $this->scopedDocument($this->rejectingId);
        $this->authorize('reject', $document);
        $this->validate(['rejectReason' => ['required', 'string', 'min:3', 'max:255']]);

        try {
            app(RejectDocumentAction::class)->handle($document, $this->rejectReason, auth()->user());
            $this->reset('rejectingId', 'rejectReason');
            $this->dispatch('toast', message: 'Document rejected.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    public function deleteDocument(int $documentId): void
    {
        $document = $this->scopedDocument($documentId);
        $this->authorize('delete', $document);

        try {
            app(DeleteDocumentAction::class)->handle($document, auth()->user());
            $this->dispatch('toast', message: 'Document removed.', variant: 'success');
        } catch (DomainException $e) {
            $this->dispatch('toast', message: $e->getMessage(), variant: 'danger');
        }
    }

    private function scopedDocument(int $documentId): Document
    {
        $documentable = $this->documentable();

        return Document::query()
            ->where('documentable_type', $documentable->getMorphClass())
            ->where('documentable_id', $documentable->getKey())
            ->with('documentable')
            ->findOrFail($documentId);
    }
}
