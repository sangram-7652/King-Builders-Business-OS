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

/**
 * UPLOADED → UNDER_REVIEW (M9). Idempotent.
 */
class SubmitDocumentForReviewAction
{
    use RunsInTransaction;

    public function handle(Document $document, User $actor): Document
    {
        return $this->transaction(function () use ($document, $actor): Document {
            /** @var Document $locked */
            $locked = Document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === DocumentStatus::UnderReview) {
                return $locked;
            }

            if ($locked->status !== DocumentStatus::Uploaded) {
                throw new DomainException("A {$locked->status->label()} document cannot be sent for review.");
            }

            $locked->forceFill(['status' => DocumentStatus::UnderReview])->save();

            $locked->loadMissing('documentType');
            [$booking, $buyer] = DocumentTimeline::targetsFor($locked);
            DocumentTimeline::record(
                DocumentActivityType::DocumentSubmittedForReview,
                "{$locked->documentType->name} submitted for review.",
                $booking, $buyer, ['document_id' => $locked->id], $actor,
            );

            return $locked->load('documentType', 'currentVersion');
        });
    }
}
