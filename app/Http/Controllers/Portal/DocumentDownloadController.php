<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Enums\CustomerActivityType;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Support\Documents\DocumentStorage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Customer-scoped document download (M15). Mirrors the staff
 * {@see \App\Http\Controllers\DocumentDownloadController} exactly — same
 * private disk, same streamed attachment — but ownership is resolved from the
 * signed-in customer's own identity and booking set (never the URL id), the
 * same pattern already used by {@see ReceiptDownloadController}. A document
 * that is neither the customer's own KYC nor attached to a booking they
 * co-own 404s, indistinguishable from "does not exist" (no IDOR signal).
 */
class DocumentDownloadController extends Controller
{
    public function __invoke(Document $document, DocumentVersion $version): StreamedResponse
    {
        /** @var Buyer $customer */
        $customer = Auth::guard('customer')->user();

        $bookingIds = $customer->bookingBuyers()->pluck('booking_id');

        $isOwnKyc = $document->documentable_type === $customer->getMorphClass()
            && $document->documentable_id === $customer->getKey();

        $isOwnBookingDoc = $document->documentable_type === (new Booking)->getMorphClass()
            && $bookingIds->contains($document->documentable_id);

        abort_unless($isOwnKyc || $isOwnBookingDoc, 404);

        abort_unless($version->document_id === $document->getKey(), 404);

        $disk = Storage::disk($version->disk ?: DocumentStorage::DISK);

        abort_unless($disk->exists($version->path), 404);

        $customer->recordPortalActivity(CustomerActivityType::DocumentDownloaded, "Downloaded document {$version->original_filename}.", [
            'document_id' => $document->id,
            'document_version_id' => $version->id,
        ]);

        return $disk->download($version->path, $version->original_filename, [
            'Content-Type' => $version->mime_type ?: 'application/octet-stream',
        ]);
    }
}
