<?php

declare(strict_types=1);

namespace App\Actions\Documents\Concerns;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Support\Documents\DocumentStorage;
use Illuminate\Http\UploadedFile;

/**
 * Appends a new immutable {@see DocumentVersion} to a document (M9). The
 * document row is locked FOR UPDATE first so two concurrent uploads get
 * distinct, sequential version numbers (the `unique(document_id, version)`
 * index is the final guard).
 */
trait CreatesDocumentVersion
{
    protected function appendVersion(Document $document, UploadedFile $file, User $actor): DocumentVersion
    {
        /** @var Document $locked */
        $locked = Document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

        $next = (int) DocumentVersion::query()->where('document_id', $locked->getKey())->max('version') + 1;

        $stored = app(DocumentStorage::class)->store($locked, $next, $file);

        $version = DocumentVersion::create([
            'document_id' => $locked->id,
            'version' => $next,
            'disk' => $stored->disk,
            'path' => $stored->path,
            'original_filename' => $stored->originalFilename,
            'mime_type' => $stored->mimeType,
            'size_bytes' => $stored->sizeBytes,
            'checksum' => $stored->checksum,
            'uploaded_by' => $actor->id,
            'uploaded_at' => now(),
        ]);

        $locked->forceFill(['current_version_id' => $version->id])->save();
        $document->setRawAttributes($locked->getAttributes());

        return $version;
    }
}
