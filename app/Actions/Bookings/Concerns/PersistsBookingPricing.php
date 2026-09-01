<?php

declare(strict_types=1);

namespace App\Actions\Bookings\Concerns;

use App\Actions\Bookings\CalculateBookingPriceAction;
use App\Models\Booking;
use App\Support\Pricing\PriceBreakdown;
use Illuminate\Support\Arr;

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
        $booking->price_overridden = collect($config['components'] ?? [])
            ->contains(fn ($row) => (bool) Arr::get($row, 'metadata.override', false));

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
