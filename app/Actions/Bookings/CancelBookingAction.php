<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Enums\PlotStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\Plot;
use App\Models\User;
use App\Support\Bookings\BookingCancellationGuard;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Cancels a booking. Transactional and idempotent.
 *
 *  - DRAFT / PENDING → CANCELLED: just releases the reservation (the
 *    `active_plot_id` generated column becomes NULL automatically).
 *  - CONFIRMED → CANCELLED: also returns the plot BOOKED → AVAILABLE, as a
 *    controlled operation inside this transaction (the generic PlotStatus map
 *    deliberately has no BOOKED → AVAILABLE edge). Refused (F-M6-1) when
 *    downstream financial / operational records still reference the booking —
 *    see {@see BookingCancellationGuard}.
 *
 * This is the cancellation FOUNDATION only. There is NO refund, penalty,
 * payment reversal or installment adjustment here — those belong to later
 * milestones.
 */
class CancelBookingAction
{
    use RunsInTransaction;

    public function __construct(private readonly BookingCancellationGuard $guard) {}

    public function handle(Booking $booking, User $actor, ?string $reason = null): Booking
    {
        return $this->transaction(function () use ($booking, $actor, $reason): Booking {
            /** @var Booking $locked */
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === BookingStatus::Cancelled) {
                return $locked->load(['project', 'block', 'plot', 'bookingBuyers.buyer', 'priceLines']);
            }

            if (! $locked->canTransitionTo(BookingStatus::Cancelled)) {
                throw new DomainException("A {$locked->status->label()} booking cannot be cancelled.");
            }

            $wasConfirmed = $locked->status === BookingStatus::Confirmed;

            // F-M6-1: a confirmed booking with live downstream state (payments,
            // an active plan, a collection / registry / possession case, a
            // transfer or a commission case) must be unwound before it can be
            // cancelled — otherwise the plot is freed while those records dangle.
            if ($wasConfirmed && ($blockers = $this->guard->blockers($locked)) !== []) {
                throw new DomainException(
                    'This confirmed booking cannot be cancelled while downstream records exist: '
                    .implode('; ', $blockers).'.'
                );
            }

            $locked->forceFill([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
            ])->save();

            if ($wasConfirmed) {
                /** @var Plot $plot */
                $plot = Plot::query()->whereKey($locked->plot_id)->lockForUpdate()->firstOrFail();

                if ($plot->status === PlotStatus::Booked) {
                    $plot->forceFill(['status' => PlotStatus::Available])->save();
                }
            }

            Log::info('booking.cancelled', [
                'booking_id' => $locked->id,
                'booking_number' => $locked->booking_number,
                'from_status' => $wasConfirmed ? 'confirmed' : 'pre-confirmation',
                'plot_released' => $wasConfirmed,
                'by' => $actor->id,
            ]);

            return $locked->load(['project', 'block', 'plot', 'bookingBuyers.buyer', 'priceLines']);
        });
    }
}
