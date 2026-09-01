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
 * UPLOADED / UNDER_REVIEW → VERIFIED (M9). `documents.verify`. Idempotent —
 * verifying an already-verified document returns it unchanged.
 */
class VerifyDocumentAction
{
    use RunsInTransaction;

    public function handle(Document $document, User $actor): Document
    {
        if (! $actor->can('documents.verify')) {
            throw new DomainException('You are not authorised to verify documents.');
        }

        return $this->transaction(function () use ($document, $actor): Document {
            /** @var Document $locked */
            $locked = Document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === DocumentStatus::Verified) {
                return $locked;
            }

            if (! in_array($locked->status, [DocumentStatus::Uploaded, DocumentStatus::UnderReview], true)) {
                throw new DomainException("A {$locked->status->label()} document cannot be verified.");
            }

            if ($locked->current_version_id === null) {
                throw new DomainException('There is no uploaded file to verify.');
            }

            $locked->forceFill([
                'status' => DocumentStatus::Verified,
                'verified_by' => $actor->id,
                'verified_at' => now(),
                'rejected_by' => null, 'rejected_at' => null, 'rejection_reason' => null,
            ])->save();

            $locked->loadMissing('documentType');
            [$booking, $buyer] = DocumentTimeline::targetsFor($locked);
            DocumentTimeline::record(
                DocumentActivityType::DocumentVerified,
                "{$locked->documentType->name} verified.",
                $booking, $buyer, ['document_id' => $locked->id], $actor,
            );

            Log::info('document.verified', ['document_id' => $locked->id, 'by' => $actor->id]);

            return $locked->load('documentType', 'currentVersion');
        });
    }
}
