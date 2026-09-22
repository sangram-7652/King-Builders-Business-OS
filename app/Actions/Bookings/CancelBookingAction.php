<?php

declare(strict_types=1);

namespace App\Actions\Bookings;

use App\Actions\Possession\PossessionCaseWorkflowAction;
use App\Actions\Registry\RegistryCaseWorkflowAction;
use App\Actions\Transfer\TransferWorkflowAction;
use App\Enums\BookingStatus;
use App\Enums\PlotStatus;
use App\Enums\PossessionCaseStatus;
use App\Enums\RegistryCaseStatus;
use App\Enums\TransferRequestStatus;
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
 *    deliberately has no BOOKED → AVAILABLE edge), and cascades cancellation
 *    to every still-ACTIVE downstream workflow — Registry Case, Possession
 *    Case, Transfer Request — through each module's OWN state machine (see
 *    {@see self::cancelActiveDownstreamRecords()}). A record already in a
 *    terminal state (Completed / Cancelled / Rejected) is left exactly as it
 *    is: preserved historical data, never force-transitioned, never deleted.
 *
 *    Product requirement: a CONFIRMED booking must always be cancellable by
 *    an authorised operator — downstream records no longer block
 *    cancellation by merely existing. {@see BookingCancellationGuard} still
 *    refuses cancellation for the small set of cases this workflow genuinely
 *    cannot resolve safely by itself (live payments, a signed agreement, or
 *    the plot already sitting in a state that cannot be safely returned to
 *    AVAILABLE — e.g. possession already physically handed over).
 *
 * This is the cancellation FOUNDATION only. There is NO refund, penalty,
 * payment reversal or installment adjustment here — a booking with money
 * already collected still needs that decided/executed through the existing
 * M7 payment-reversal flow; this action only ever exposes the booking as
 * Cancelled and leaves payment history exactly as it was.
 */
class CancelBookingAction
{
    use RunsInTransaction;

    public function __construct(
        private readonly BookingCancellationGuard $guard,
        private readonly RegistryCaseWorkflowAction $registryWorkflow,
        private readonly PossessionCaseWorkflowAction $possessionWorkflow,
        private readonly TransferWorkflowAction $transferWorkflow,
    ) {}

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
            $plot = null;

            if ($wasConfirmed) {
                // Lock the plot BEFORE validating — the guard's plot-state
                // check must read the exact row we're about to (maybe)
                // mutate, not a second, unlocked query that could be stale
                // the instant another transaction commits.
                /** @var Plot $plot */
                $plot = Plot::query()->whereKey($locked->plot_id)->lockForUpdate()->firstOrFail();
                $locked->setRelation('plot', $plot);

                if (($blockers = $this->guard->blockers($locked)) !== []) {
                    throw new DomainException(
                        'This confirmed booking cannot be cancelled: '.implode('; ', $blockers).'.'
                    );
                }

                // Handle active downstream workflows BEFORE flipping the
                // booking itself — if any of these throws (e.g. a record was
                // concurrently completed after the check above), the whole
                // transaction rolls back and the booking stays Confirmed.
                $this->cancelActiveDownstreamRecords($locked, $actor, $reason);
            }

            $locked->forceFill([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
            ])->save();

            if ($wasConfirmed && $plot->status === PlotStatus::Booked) {
                $plot->forceFill(['status' => PlotStatus::Available])->save();
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

    /**
     * Cascades booking cancellation to every downstream workflow that is
     * still ACTIVE, using each module's OWN state machine — never a raw
     * status write, and never on a record already in a terminal state
     * (Completed / Cancelled / Rejected are left exactly as they are,
     * preserved as historical data). This is what lets a confirmed booking
     * with a COMPLETED Registry Case stay cancellable: only the booking's
     * own status changes; the historical case is untouched.
     *
     * Each workflow action re-locks and re-validates its own record, so a
     * record that was concurrently completed between the guard check above
     * and this call safely aborts the WHOLE transaction (via the exception
     * each `cancel()` throws for a terminal state) rather than leaving a
     * half-cancelled booking.
     */
    private function cancelActiveDownstreamRecords(Booking $booking, User $actor, ?string $reason): void
    {
        $cancelReason = ($reason !== null && $reason !== '') ? $reason : 'Booking cancelled.';

        $registryCase = $booking->registryCase()->first();
        if ($registryCase !== null && ! in_array($registryCase->status, [RegistryCaseStatus::Completed, RegistryCaseStatus::Cancelled], true)) {
            $this->registryWorkflow->cancel($registryCase, $cancelReason, $actor);
        }

        $possessionCase = $booking->possessionCase()->first();
        if ($possessionCase !== null && ! in_array($possessionCase->status, [PossessionCaseStatus::Completed, PossessionCaseStatus::Cancelled], true)) {
            $this->possessionWorkflow->cancel($possessionCase, $cancelReason, $actor);
        }

        $activeTransfers = $booking->transferRequests()
            ->whereNotIn('status', [
                TransferRequestStatus::Cancelled->value,
                TransferRequestStatus::Rejected->value,
                TransferRequestStatus::Completed->value,
            ])
            ->get();

        foreach ($activeTransfers as $transfer) {
            $this->transferWorkflow->cancelForBookingCancellation($transfer, $cancelReason, $actor);
        }
    }
}
