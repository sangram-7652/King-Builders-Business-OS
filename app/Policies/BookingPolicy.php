<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\BookingStatus;
use App\Enums\Permission;
use App\Models\Booking;
use App\Models\User;

/**
 * SUPER ADMIN bypasses all of these via Gate::before (AppServiceProvider).
 *
 * Every check is a permission check — never a raw role check — and the domain
 * actions enforce the same rules again, so the UI can never be the only guard.
 */
class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::BookingsView->value);
    }

    public function view(User $user, Booking $booking): bool
    {
        return $user->can(Permission::BookingsView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::BookingsCreate->value);
    }

    public function update(User $user, Booking $booking): bool
    {
        return $user->can(Permission::BookingsUpdate->value) && $booking->isEditable();
    }

    public function confirm(User $user, Booking $booking): bool
    {
        return $user->can(Permission::BookingsConfirm->value) && $booking->isPending();
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return $user->can(Permission::BookingsCancel->value)
            && $booking->canTransitionTo(BookingStatus::Cancelled);
    }

    public function delete(User $user, Booking $booking): bool
    {
        return $user->can(Permission::BookingsDelete->value)
            && ($booking->isDraft() || $booking->isCancelled());
    }

    /** View the pricing breakdown / snapshot. */
    public function viewPricing(User $user, Booking $booking): bool
    {
        return $user->can(Permission::PricingView->value) || $user->can(Permission::BookingsView->value);
    }

    /** Manually override the calculated price. */
    public function overridePricing(User $user, Booking $booking): bool
    {
        return $user->can(Permission::PricingOverride->value) && $booking->isEditable();
    }

    /** Set / change the booking's channel-partner attribution (M14.2). */
    public function attributePartners(User $user, Booking $booking): bool
    {
        return $user->can(Permission::PartnersAttribute->value)
            && $user->can(Permission::BookingsView->value)
            && ! $booking->isCancelled();
    }
}
