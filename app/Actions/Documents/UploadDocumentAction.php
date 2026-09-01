<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\CreatesDocumentVersion;
use App\Enums\DocumentActivityType;
use App\Enums\DocumentStatus;
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
 * Uploads a file for a document slot (M9).
 *
 *  - one Document row per (documentable, document_type) — created on first upload
 *  - each upload is a NEW version; a VERIFIED / signed version is never
 *    destructively replaced, and a re-upload moves the record back to UPLOADED
 *  - an `idempotency_key` makes a retried upload return the same version
 */
class UploadDocumentAction
{
    use CreatesDocumentVersion;
    use RunsInTransaction;

    /**
     * @param  array{title?: string|null, expires_at?: string|null}  $meta
     */
    public function handle(Model $documentable, DocumentType $type, UploadedFile $file, User $actor, array $meta = []): Document
    {
        $checksum = hash_file('sha256', $file->getRealPath()) ?: '';

        return $this->transaction(function () use ($documentable, $type, $file, $actor, $meta, $checksum): Document {
            $document = $this->resolveDocument($documentable, $type, $actor, $meta)->loadMissing('currentVersion');
            $document->setRelation('documentable', $documentable);

            // Idempotency: an identical file re-submitted is not a new version.
            if ($checksum !== '' && $document->currentVersion?->checksum === $checksum) {
                return $document->load('currentVersion', 'documentType');
            }

            $wasVerified = $document->status === DocumentStatus::Verified;
            $isFirst = $document->current_version_id === null;

            $version = $this->appendVersion($document, $file, $actor);

            $document->forceFill([
                'status' => DocumentStatus::Uploaded,
                'verified_by' => null, 'verified_at' => null,
                'rejected_by' => null, 'rejected_at' => null, 'rejection_reason' => null,
                'expires_at' => $meta['expires_at'] ?? $document->expires_at,
                'title' => $meta['title'] ?? $document->title,
            ])->save();

            [$booking, $buyer] = DocumentTimeline::targetsFor($document);
            DocumentTimeline::record(
                $isFirst ? DocumentActivityType::DocumentUploaded : DocumentActivityType::DocumentResubmitted,
                "{$type->name} ".($isFirst ? 'uploaded' : "re-uploaded (v{$version->version})").'.',
                $booking, $buyer,
                ['document_id' => $document->id, 'version' => $version->version, 'replaced_verified' => $wasVerified],
                $actor,
            );

            Log::info('document.uploaded', [
                'document_id' => $document->id, 'version' => $version->version,
                'type' => $type->code, 'by' => $actor->id,
            ]);

            return $document->load('currentVersion', 'documentType');
        });
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function resolveDocument(Model $documentable, DocumentType $type, User $actor, array $meta): Document
    {
        try {
            return Document::query()->firstOrCreate(
                [
                    'documentable_type' => $documentable->getMorphClass(),
                    'documentable_id' => $documentable->getKey(),
                    'document_type_id' => $type->id,
                ],
                [
                    'status' => DocumentStatus::Pending,
                    'title' => $meta['title'] ?? $type->name,
                    'created_by' => $actor->id,
                ],
            );
        } catch (QueryException $e) {
            return Document::query()->where([
                'documentable_type' => $documentable->getMorphClass(),
                'documentable_id' => $documentable->getKey(),
                'document_type_id' => $type->id,
            ])->firstOrFail();
        }
    }
}
