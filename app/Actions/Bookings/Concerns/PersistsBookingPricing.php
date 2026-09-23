<?php

declare(strict_types=1);

namespace App\Actions\Bookings\Concerns;

use App\Actions\Bookings\CalculateBookingPriceAction;
use App\Models\Booking;
use App\Support\Pricing\PriceBreakdown;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Writes an engine-computed {@see PriceBreakdown} onto a booking: the money
 * columns, the `booking_price_lines` rows, and (on confirm) the frozen
 * `pricing_snapshot`. The booking must already exist and be saved.
 */
trait PersistsBookingPricing
{
    /**
     * @param  array{base_area?: mixed, base_rate?: mixed, components?: array<int, array<string, mixed>>}  $config
     */
    protected function applyPricing(Booking $booking, array $config, bool $freezeSnapshot = false): PriceBreakdown
    {
        $breakdown = app(CalculateBookingPriceAction::class)->handle($config);

        $booking->fill($breakdown->toBookingAttributes());

        // The override flag AND its who/when/why are derived from the
        // override line itself — never set independently — so they can
        // never drift apart: an override line present ⇒ all four set from
        // its metadata; absent ⇒ all four cleared together.
        $override = collect($config['components'] ?? [])
            ->first(fn ($row) => (bool) Arr::get($row, 'metadata.override', false));

        $booking->forceFill([
            'price_overridden' => $override !== null,
            'price_override_by' => $override !== null ? Arr::get($override, 'metadata.by') : null,
            'price_override_at' => $override !== null && Arr::get($override, 'metadata.at') !== null
                ? Carbon::parse((string) Arr::get($override, 'metadata.at'))
                : null,
            'price_override_reason' => $override !== null ? Arr::get($override, 'metadata.reason') : null,
        ]);

        if ($freezeSnapshot) {
            $booking->pricing_snapshot = $breakdown->toSnapshot();
        }

        $booking->save();

        $booking->priceLines()->delete();

        foreach ($breakdown->lines as $line) {
            $booking->priceLines()->create($line->toLineAttributes());
        }

        $booking->load('priceLines');

        return $breakdown;
    }
}
