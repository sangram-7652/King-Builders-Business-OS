<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Enums\DocumentActivityType;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Support\Documents\DocumentTimeline;
use Illuminate\Support\Carbon;

/**
 * Marks documents whose `expires_at` has passed as EXPIRED (M9). Idempotent —
 * safe to run from the scheduler every day. Only touches non-terminal states.
 */
class ExpireDocumentsAction
{
    public function handle(?Carbon $asOf = null): int
    {
        $today = ($asOf ?? Carbon::today())->startOfDay();
        $count = 0;

        Document::query()
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', $today->toDateString())
            ->whereIn('status', [DocumentStatus::Uploaded->value, DocumentStatus::UnderReview->value, DocumentStatus::Verified->value])
            ->with('documentType')
            ->chunkById(200, function ($documents) use (&$count): void {
                foreach ($documents as $document) {
                    $document->forceFill(['status' => DocumentStatus::Expired])->save();

                    [$booking, $buyer] = DocumentTimeline::targetsFor($document);
                    DocumentTimeline::record(
                        DocumentActivityType::DocumentExpired,
                        "{$document->documentType->name} expired on {$document->expires_at->format('d M Y')}.",
                        $booking, $buyer, ['document_id' => $document->id],
                    );
                    $count++;
                }
            });

        return $count;
    }
}
