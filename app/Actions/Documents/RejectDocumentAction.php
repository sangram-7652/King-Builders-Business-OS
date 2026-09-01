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
 * UPLOADED / UNDER_REVIEW → REJECTED (M9) with a mandatory reason. The buyer /
 * team then re-uploads (a new version), which moves the record back to UPLOADED.
 */
class RejectDocumentAction
{
    use RunsInTransaction;

    public function handle(Document $document, string $reason, User $actor): Document
    {
        if (! $actor->can('documents.reject')) {
            throw new DomainException('You are not authorised to reject documents.');
        }

        if (trim($reason) === '') {
            throw new DomainException('A rejection reason is required.');
        }

        return $this->transaction(function () use ($document, $reason, $actor): Document {
            /** @var Document $locked */
            $locked = Document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === DocumentStatus::Rejected) {
                return $locked;
            }

            if (! in_array($locked->status, [DocumentStatus::Uploaded, DocumentStatus::UnderReview, DocumentStatus::Verified], true)) {
                throw new DomainException("A {$locked->status->label()} document cannot be rejected.");
            }

            $locked->forceFill([
                'status' => DocumentStatus::Rejected,
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'rejection_reason' => trim($reason),
                'verified_by' => null, 'verified_at' => null,
            ])->save();

            $locked->loadMissing('documentType');
            [$booking, $buyer] = DocumentTimeline::targetsFor($locked);
            DocumentTimeline::record(
                DocumentActivityType::DocumentRejected,
                "{$locked->documentType->name} rejected: ".trim($reason),
                $booking, $buyer, ['document_id' => $locked->id], $actor,
            );

            Log::info('document.rejected', ['document_id' => $locked->id, 'by' => $actor->id]);

            return $locked->load('documentType', 'currentVersion');
        });
    }
}
