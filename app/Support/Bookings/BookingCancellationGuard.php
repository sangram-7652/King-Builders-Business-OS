<?php

declare(strict_types=1);

namespace App\Support\Bookings;

use App\Enums\AgreementStatus;
use App\Enums\PaymentStatus;
use App\Enums\PossessionCaseStatus;
use App\Enums\RegistryCaseStatus;
use App\Enums\TransferRequestStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Observers\BookingCommissionObserver;

/**
 * Decides whether a CONFIRMED booking can still be cancelled through the normal
 * cancellation path (M6, finding F-M6-1).
 *
 * A confirmed booking whose downstream financial / operational records already
 * exist must NOT be cancellable — cancelling it would free the plot while
 * payments, a registry case, a possession case, an ownership transfer or a
 * signed agreement still reference it. Those records must be unwound first
 * (reverse the payments, close the case, …).
 *
 * Commission cases (M14) are deliberately NOT a blocker: the existing
 * {@see BookingCommissionObserver} already cascades a booking
 * cancellation to its commission cases (cancel the pending ones, auto-reverse
 * the approved-unpaid ones, flag the partially-paid ones for manual reversal).
 *
 * DRAFT / PENDING bookings are unaffected — they hold no financial history.
 * This guard invents no "force cancel" flow: if a blocker is present, the
 * caller gets a clear {@see DomainException}.
 */
final class BookingCancellationGuard
{
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

        // M9 — a live registry case.
        if ($booking->registryCase()
            ->where('status', '!=', RegistryCaseStatus::Cancelled->value)
            ->exists()
        ) {
            $blockers[] = 'a registry case exists — cancel it first';
        }

        // M9 — a signed / approved agreement.
        if ($booking->agreements()
            ->whereIn('status', [AgreementStatus::Signed->value, AgreementStatus::Approved->value])
            ->exists()
        ) {
            $blockers[] = 'a signed agreement exists';
        }

        // M10 — a live possession case.
        if ($booking->possessionCase()
            ->where('status', '!=', PossessionCaseStatus::Cancelled->value)
            ->exists()
        ) {
            $blockers[] = 'a possession case exists — cancel it first';
        }

        // M10 — an ownership transfer request that is not terminated.
        if ($booking->transferRequests()
            ->whereNotIn('status', [TransferRequestStatus::Cancelled->value, TransferRequestStatus::Rejected->value])
            ->exists()
        ) {
            $blockers[] = 'an ownership transfer request is in progress';
        }

        return $blockers;
    }
}
