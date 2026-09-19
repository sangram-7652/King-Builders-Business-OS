<?php

declare(strict_types=1);

namespace App\Actions\Partners;

use App\Models\Booking;
use App\Models\User;

/**
 * Clears a booking's promoter attribution (M14.2) — marks it a direct sale.
 * Thin wrapper over {@see SetBookingPartnerAttribution} with no promoter so
 * the supersede-not-mutate history rules stay in one place.
 */
class RemoveBookingPartnerAttribution
{
    public function __construct(private readonly SetBookingPartnerAttribution $set) {}

    public function handle(Booking $booking, User $actor, ?string $reason = null): Booking
    {
        return $this->set->handle($booking, null, $actor, $reason);
    }
}
