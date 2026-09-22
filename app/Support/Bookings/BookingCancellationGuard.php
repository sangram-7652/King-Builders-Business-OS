<?php

declare(strict_types=1);

namespace App\Support\Bookings;

use App\Actions\Bookings\CancelBookingAction;
use App\Actions\Registry\RegistryCaseWorkflowAction;
use App\Enums\AgreementStatus;
use App\Enums\PaymentStatus;
use App\Enums\PlotStatus;
use App\Models\Booking;

/**
 * Decides whether a CONFIRMED booking can still be cancelled (F-M6-1,
 * redesigned for the product requirement that "a confirmed booking must
 * always be cancellable by an authorised operator" — see
 * {@see CancelBookingAction}).
 *
 * This guard now reports ONLY the blockers the cancellation workflow
 * genuinely cannot resolve safely on its own:
 *
 *  - Live money (a PENDING or SUCCESS payment) — cancelling for free while
 *    money is held or in flight would force a refund/reversal decision this
 *    guard must never make silently. The operator reverses the payment
 *    through the existing M7 payment-reversal flow first; the message says
 *    so.
 *  - A signed / approved Agreement — a legally executed document, unrelated
 *    to Registry/Possession/Transfer and deliberately left untouched here.
 *  - The plot itself already sitting in a state cancellation cannot safely
 *    undo (SOLD / POSSESSION_COMPLETED / TRANSFERRED / already CANCELLED) —
 *    forcing such a plot back to AVAILABLE would risk double-occupancy or a
 *    double-sale.
 *
 * Registry / Possession / Transfer are DELIBERATELY NOT checked here any
 * more. A still-active one is cancelled through its own state machine as
 * part of the SAME cancellation transaction (see
 * `CancelBookingAction::cancelActiveDownstreamRecords()`); a COMPLETED /
 * terminal one is simply left alone as preserved historical data. Blocking
 * on a completed Registry Case in particular used to make the booking
 * PERMANENTLY stuck — {@see RegistryCaseWorkflowAction::cancel()}
 * refuses to cancel a completed case, so "cancel it first" was never
 * actually possible. Completing Possession always also flips the plot to
 * POSSESSION_COMPLETED, and completing a Transfer always leaves the
 * booking's CURRENT plot BOOKED (the transfer moves the booking onto a new
 * plot) — so the plot-state check above is what actually catches the one
 * remaining unsafe case (possession already physically handed over)
 * without needing to special-case Possession by name.
 *
 * An empty list means cancellation is safe.
 */
final class BookingCancellationGuard
{
    /** Plot states a booking cancellation must never try to reverse. */
    private const UNRECOVERABLE_PLOT_STATES = [
        PlotStatus::Sold,
        PlotStatus::PossessionCompleted,
        PlotStatus::Transferred,
        PlotStatus::Cancelled,
    ];

    /**
     * Human-readable reasons the confirmed booking cannot be safely cancelled.
     * An empty list means cancellation is safe.
     *
     * @return list<string>
     */
    public function blockers(Booking $booking): array
    {
        $blockers = [];

        // M7 — money already collected, or a payment still in flight. A
        // FAILED / CANCELLED / REVERSED payment carries no live balance.
        $livePayments = $booking->payments()
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Success->value])
            ->count();

        if ($livePayments > 0) {
            $blockers[] = "{$livePayments} pending/successful payment(s) exist — reverse them first";
        }

        // M9 — a signed / approved agreement.
        if ($booking->agreements()
            ->whereIn('status', [AgreementStatus::Signed->value, AgreementStatus::Approved->value])
            ->exists()
        ) {
            $blockers[] = 'a signed agreement exists';
        }

        // The plot itself must still be in a state cancellation can safely
        // undo. Use the already-loaded relation when the caller has it (the
        // caller typically holds a FOR UPDATE lock on it already) so this
        // check reads the exact same row, not a stale second query.
        $plot = $booking->relationLoaded('plot') ? $booking->plot : $booking->plot()->first();

        if ($plot !== null && in_array($plot->status, self::UNRECOVERABLE_PLOT_STATES, true)) {
            $blockers[] = "the plot is already {$plot->status->label()} and cannot be safely returned to Available — this needs manual review before the booking can be cancelled";
        }

        return $blockers;
    }
}
