<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\CreatesDocumentVersion;
use App\Enums\DocumentActivityType;
use App\Enums\DocumentStatus;
use App\Exceptions\DomainException;
use App\Models\Document;
use App\Models\Masters\DocumentType;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Documents\DocumentTimeline;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Adds ANOTHER independent file for a multi-upload document type (M9) —
 * e.g. Payment Documents / Registry Documents on a booking, where several
 * unrelated files (a cheque scan, an NEFT receipt, a photo of a proof) must
 * each stay a distinct record, never collapsed into one another as versions
 * of "the same" document.
 *
 * This is the multi-file counterpart to {@see UploadDocumentAction}, which
 * remains the single-slot upload/replace/version path (Booking Form, buyer
 * KYC, …) and is untouched. Both share the exact same `Document` /
 * `DocumentVersion` models, storage, and versioning trait — this action only
 * differs in how the `Document` row is resolved: always a NEW row (next
 * `sequence`) instead of finding-or-creating the one slot for the type.
 *
 * Refuses to run against a type that isn't flagged `allows_multiple` — that
 * type must go through `UploadDocumentAction` instead, which enforces the
 * one-row-per-slot invariant.
 */
class AddDocumentAction
{
    use CreatesDocumentVersion;
    use RunsInTransaction;

    /**
     * @param  array{title?: string|null}  $meta
     */
    public function handle(Model $documentable, DocumentType $type, UploadedFile $file, User $actor, array $meta = []): Document
    {
        if (! $type->allows_multiple) {
            throw new DomainException("{$type->name} does not support multiple files — use the single upload slot instead.");
        }

        return $this->transaction(function () use ($documentable, $type, $file, $actor, $meta): Document {
            $document = $this->createDocument($documentable, $type, $actor, $meta);
            $version = $this->appendVersion($document, $file, $actor);

            $document->forceFill(['status' => DocumentStatus::Uploaded])->save();

            [$booking, $buyer] = DocumentTimeline::targetsFor($document);
            DocumentTimeline::record(
                DocumentActivityType::DocumentUploaded,
                "{$type->name} uploaded ({$version->original_filename}).",
                $booking, $buyer,
                ['document_id' => $document->id, 'sequence' => $document->sequence],
                $actor,
            );

            Log::info('document.added', [
                'document_id' => $document->id, 'sequence' => $document->sequence,
                'type' => $type->code, 'by' => $actor->id,
            ]);

            return $document->load('currentVersion', 'documentType');
        });
    }

    /**
     * Always creates a NEW Document row at the next sequence for this
     * (documentable, type) pair — never finds/reuses an existing one. A
     * bounded retry absorbs a concurrent insert racing for the same sequence
     * (the same pattern {@see UploadDocumentAction::resolveDocument()} uses
     * for its own unique-constraint race).
     *
     * @param  array<string, mixed>  $meta
     */
    private function createDocument(Model $documentable, DocumentType $type, User $actor, array $meta): Document
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $next = (int) Document::query()
                ->where('documentable_type', $documentable->getMorphClass())
                ->where('documentable_id', $documentable->getKey())
                ->where('document_type_id', $type->id)
                ->max('sequence') + 1;

            try {
                $document = Document::create([
                    'documentable_type' => $documentable->getMorphClass(),
                    'documentable_id' => $documentable->getKey(),
                    'document_type_id' => $type->id,
                    'sequence' => $next,
                    'status' => DocumentStatus::Pending,
                    'title' => $meta['title'] ?? $type->name,
                    'created_by' => $actor->id,
                ]);
                $document->setRelation('documentable', $documentable);

                return $document;
            } catch (QueryException $e) {
                if ($attempt === 5) {
                    throw $e;
                }
                // Another concurrent upload claimed this sequence — retry with a fresh max().
            }
        }

        throw new DomainException('Could not add the document — please try again.'); // unreachable
    }
}
