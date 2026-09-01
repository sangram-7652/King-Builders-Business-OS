<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Models\Document;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Writes uploaded files to the PRIVATE `documents` disk (M9).
 *
 * The stored path always contains a random, non-guessable component and the
 * disk is never web-served (no `serve`, no `url`) — the only way to a file is
 * App\Http\Controllers\DocumentDownloadController after an authorization check.
 */
final class DocumentStorage
{
    public const DISK = 'documents';

    public function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    public function store(Document $document, int $version, UploadedFile $file): StoredFile
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $dir = sprintf('%s/%d', Str::of($document->documentable_type)->afterLast('\\')->snake(), $document->getKey());
        $name = sprintf('v%d-%s.%s', $version, Str::random(40), $ext);

        $path = $this->disk()->putFileAs($dir, $file, $name);

        return new StoredFile(
            disk: self::DISK,
            path: $path,
            originalFilename: $file->getClientOriginalName() ?: $name,
            mimeType: $file->getClientMimeType() ?: $file->getMimeType(),
            sizeBytes: (int) $file->getSize(),
            checksum: hash_file('sha256', $file->getRealPath()) ?: '',
        );
    }
}
