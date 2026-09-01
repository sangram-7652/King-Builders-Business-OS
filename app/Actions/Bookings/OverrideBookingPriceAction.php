<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Bookings\Concerns\PersistsBookingPricing;
use App\Enums\PriceComponentType;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\User;
use App\Services\Pricing\PricingEngine;
use App\Support\Concerns\RunsInTransaction;
use App\Support\Money;
use App\Support\Pricing\PriceComponentInput;
use App\Support\Pricing\PricingRequest;
use Illuminate\Support\Facades\Log;

/**
 * Manual price override for an editable booking.
 *
 *  - only a holder of `pricing.override` (enforced here AND in the policy/UI)
 *  - a reason is mandatory and recorded, with who and when
 *  - the calculated figures are NOT silently mutated: the override is added as
 *    an explicit, labelled CHARGE / DISCOUNT line carrying `metadata.override`,
 *    so the breakdown still shows exactly where the difference comes from.
 */
class OverrideBookingPriceAction
{
    use PersistsBookingPricing;
    use RunsInTransaction;

    public function __construct(private readonly PricingEngine $engine) {}

    public function handle(Booking $booking, string $targetFinalAmount, string $reason, User $actor): Booking
    {
        if (! $actor->can('pricing.override')) {
            throw new DomainException('You are not authorised to override booking pricing.');
        }

        if (trim($reason) === '') {
            throw new DomainException('A price override needs a reason.');
        }

        if (! $booking->isEditable()) {
            throw new DomainException('Pricing can only be overridden while a booking is a draft or pending.');
        }

        $target = Money::of($targetFinalAmount);

        if ($target->isNegative()) {
            throw new DomainException('The overridden final amount cannot be negative.');
        }

        return $this->transaction(function () use ($booking, $target, $reason, $actor): Booking {
            /** @var Booking $locked */
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
            $locked->load('priceLines');

            // Base config without any prior override line.
            $config = CalculateBookingPriceAction::configFromBooking($locked);
            $config['components'] = array_values(array_filter(
                $config['components'],
                fn ($c) => ! (bool) data_get($c, 'metadata.override', false),
            ));

            $baseline = $this->engine->calculate(new PricingRequest(
                baseArea: $config['base_area'],
                baseRate: $config['base_rate'],
                components: array_map(fn ($c) => PriceComponentInput::fromArray($c), $config['components']),
            ));

            $delta = $target->minus($baseline->finalAmount);

            if (! $delta->isZero()) {
                $config['components'][] = [
                    'type' => $delta->isNegative() ? PriceComponentType::Discount->value : PriceComponentType::Charge->value,
                    'name' => 'Manual price override',
                    'calculation_type' => 'fixed',
                    'rate' => $delta->abs()->store(),
                    'quantity' => null,
                    'metadata' => [
                        'override' => true,
                        'reason' => trim($reason),
                        'by' => $actor->id,
                        'at' => now()->toIso8601String(),
                        'target_final' => $target->store(),
                    ],
                ];
            }

            $this->applyPricing($locked, $config);

            $locked->forceFill([
                'price_overridden' => ! $delta->isZero(),
                'price_override_by' => $delta->isZero() ? null : $actor->id,
                'price_override_at' => $delta->isZero() ? null : now(),
                'price_override_reason' => $delta->isZero() ? null : trim($reason),
            ])->save();

            Log::info('booking.price_overridden', [
                'booking_id' => $locked->id,
                'booking_number' => $locked->booking_number,
                'final_amount' => $locked->final_amount,
                'by' => $actor->id,
            ]);

            return $locked->load(['project', 'block', 'plot', 'bookingBuyers.buyer', 'priceLines']);
        });
    }
}
