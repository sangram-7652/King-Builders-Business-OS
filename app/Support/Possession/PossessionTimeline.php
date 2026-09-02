<?php

declare(strict_types=1);

namespace App\Support\Possession;

use App\Enums\PossessionActivityType;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\Plot;
use App\Models\PossessionActivity;
use App\Models\User;

/**
 * The single writer for the M10 possession / transfer / ownership timeline.
 * Reuses the lightweight append-only activity pattern (M5 / M8 / M9).
 */
final class PossessionTimeline
{
    /**
     * @param  array<string, scalar|null>  $properties  never PII
     */
    public static function record(
        PossessionActivityType $type,
        string $description,
        ?Booking $booking = null,
        ?Plot $plot = null,
        ?Buyer $buyer = null,
        array $properties = [],
        ?User $causer = null,
    ): PossessionActivity {
        return PossessionActivity::create([
            'booking_id' => $booking?->getKey(),
            'plot_id' => $plot?->getKey() ?? $booking?->plot_id,
            'buyer_id' => $buyer?->getKey(),
            'type' => $type,
            'description' => $description,
            'properties' => $properties === [] ? null : $properties,
            'causer_id' => ($causer ?? auth()->user())?->id,
            'created_at' => now(),
        ]);
    }
}
