<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Bookings\Concerns\PersistsBookingPricing;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\User;
use App\Services\Pricing\PriceOverrideService;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

/**
 * Manual price override for an editable booking — the ONLY way an override
 * is created or removed.
 *
 *  - only a holder of `pricing.override` (enforced here AND in the policy/UI)
 *  - a reason is mandatory and recorded, with who and when
 *  - the calculated figures are NOT silently mutated: the override is added as
 *    an explicit, labelled CHARGE / DISCOUNT line carrying `metadata.override`,
 *    so the breakdown still shows exactly where the difference comes from
 *    (representation owned by {@see PriceOverrideService}).
 *
 * A target equal to the calculated price, or {@see self::remove()}, clears
 * the override together with ALL of its metadata (flag, reason, user, time).
 */
class OverrideBookingPriceAction
{
    use PersistsBookingPricing;
    use RunsInTransaction;

    public function __construct(private readonly PriceOverrideService $overrides) {}

    public function handle(Booking $booking, string $targetFinalAmount, string $reason, User $actor): Booking
    {
        $this->assertAllowed($booking, $actor);

        if (trim($reason) === '') {
            throw new DomainException('A price override needs a reason.');
        }

        $target = Money::of($targetFinalAmount);

        if ($target->isNegative()) {
            throw new DomainException('The overridden final amount cannot be negative.');
        }

        return $this->transaction(function () use ($booking, $target, $reason, $actor): Booking {
            $locked = $this->lock($booking);

            $config = $this->overrides->apply(CalculateBookingPriceAction::configFromBooking($locked), [
                'target_final' => $target->store(),
                'reason' => trim($reason),
                'by' => $actor->id,
                'at' => now()->toIso8601String(),
            ], keepWhenZero: false);

            $this->applyPricing($locked, $config);

            Log::info('booking.price_overridden', [
                'booking_id' => $locked->id,
                'booking_number' => $locked->booking_number,
                'final_amount' => $locked->final_amount,
                'by' => $actor->id,
            ]);

            return $locked->load(['project', 'block', 'plot', 'bookingBuyers.buyer', 'priceLines']);
        });
    }

    /** Intentionally remove an override: back to the calculated price, all override metadata cleared. */
    public function remove(Booking $booking, User $actor): Booking
    {
        $this->assertAllowed($booking, $actor);

        return $this->transaction(function () use ($booking, $actor): Booking {
            $locked = $this->lock($booking);

            $config = CalculateBookingPriceAction::configFromBooking($locked);
            $config['components'] = PriceOverrideService::withoutOverrides($config['components']);

            $this->applyPricing($locked, $config);

            Log::info('booking.price_override_removed', [
                'booking_id' => $locked->id,
                'booking_number' => $locked->booking_number,
                'final_amount' => $locked->final_amount,
                'by' => $actor->id,
            ]);

            return $locked->load(['project', 'block', 'plot', 'bookingBuyers.buyer', 'priceLines']);
        });
    }

    private function assertAllowed(Booking $booking, User $actor): void
    {
        if (! $actor->can('pricing.override')) {
            throw new DomainException('You are not authorised to override booking pricing.');
        }

        if (! $booking->isEditable()) {
            throw new DomainException('Pricing can only be overridden while a booking is a draft or pending.');
        }
    }

    private function lock(Booking $booking): Booking
    {
        /** @var Booking $locked */
        $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

        return $locked->load('priceLines');
    }
}
