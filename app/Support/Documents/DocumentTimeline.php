<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Enums\DocumentActivityType;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Document;
use App\Models\DocumentActivity;
use App\Models\User;

/**
 * The single writer for the M9 documentation / agreement / registry timeline.
 * Reuses the lightweight append-only activity pattern (M5 / M8).
 */
final class DocumentTimeline
{
    /**
     * @param  array<string, scalar|null>  $properties  never PII
     */
    public static function record(
        DocumentActivityType $type,
        string $description,
        ?Booking $booking = null,
        ?Buyer $buyer = null,
        array $properties = [],
        ?User $causer = null,
    ): DocumentActivity {
        return DocumentActivity::create([
            'booking_id' => $booking?->getKey(),
            'buyer_id' => $buyer?->getKey(),
            'type' => $type,
            'description' => $description,
            'properties' => $properties === [] ? null : $properties,
            'causer_id' => ($causer ?? auth()->user())?->id,
            'created_at' => now(),
        ]);
    }

    /**
     * Resolve the booking / buyer a document's events belong to.
     *
     * @return array{0: Booking|null, 1: Buyer|null}
     */
    public static function targetsFor(Document $document): array
    {
        $document->loadMissing('documentable');
        $d = $document->documentable;

        return match (true) {
            $d instanceof Booking => [$d, null],
            $d instanceof Buyer => [null, $d],
            default => [null, null],
        };
    }
}
