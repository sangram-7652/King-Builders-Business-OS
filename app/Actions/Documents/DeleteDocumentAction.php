<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Enums\DocumentActivityType;
use App\Enums\DocumentStatus;
use App\Exceptions\DomainException;
use App\Models\Document;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Documents\DocumentTimeline;
use Illuminate\Support\Facades\Log;

/**
 * Soft-deletes a document (M9). `documents.delete` only. A VERIFIED document is
 * protected — it cannot be removed through the app; the file versions are kept
 * as historical record regardless.
 */
class DeleteDocumentAction
{
    use RunsInTransaction;

    public function handle(Document $document, User $actor): void
    {
        if (! $actor->can('documents.delete')) {
            throw new DomainException('You are not authorised to delete documents.');
        }

        if ($document->isProtected()) {
            throw new DomainException('A verified document is protected and cannot be deleted.');
        }

        $this->transaction(function () use ($document, $actor): void {
            $document->loadMissing('documentType');
            [$booking, $buyer] = DocumentTimeline::targetsFor($document);

            $document->forceFill(['status' => DocumentStatus::Pending])->save();
            $document->delete();

            DocumentTimeline::record(
                DocumentActivityType::DocumentDeleted,
                "{$document->documentType->name} removed.",
                $booking, $buyer, ['document_id' => $document->id], $actor,
            );

            Log::info('document.deleted', ['document_id' => $document->id, 'by' => $actor->id]);
        });
    }
}
