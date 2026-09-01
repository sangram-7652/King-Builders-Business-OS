<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Policies\DocumentPolicy;
use App\Support\Documents\DocumentStorage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The ONLY path to a stored document file (M9). The private `documents` disk is
 * never web-served — every download runs through here and is authorised by
 * {@see DocumentPolicy::download} (module permission + the user
 * can view the underlying Buyer / Booking). Guessing an id (IDOR) fails.
 */
class DocumentDownloadController extends Controller
{
    public function __invoke(Document $document, DocumentVersion $version): StreamedResponse
    {
        Gate::authorize('download', $document);

        abort_unless($version->document_id === $document->getKey(), 404);

        $disk = Storage::disk($version->disk ?: DocumentStorage::DISK);

        abort_unless($disk->exists($version->path), 404);

        return $disk->download($version->path, $version->original_filename, [
            'Content-Type' => $version->mime_type ?: 'application/octet-stream',
        ]);
    }
}
