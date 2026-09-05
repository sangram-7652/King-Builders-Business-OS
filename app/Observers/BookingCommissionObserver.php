<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\BookingStatus;
use App\Enums\CommissionCaseEventType;
use App\Enums\CommissionCaseStatus;
use App\Models\Booking;
use App\Models\CommissionCase;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Keeps commission cases in step with the M6 booking lifecycle (M14.4).
 *
 * When a booking is cancelled:
 *   - PENDING_REVIEW / ON_HOLD cases are CANCELLED (nothing committed)
 *   - APPROVED cases with nothing paid out are auto-REVERSED (safe, clawback 0)
 *   - PARTIALLY_PAID / PAID cases are left for a human to REVERSE (real money to
 *     recover) and only logged here
 */
class BookingCommissionObserver
{
    public function updated(Booking $booking): void
    {
        if (! $booking->wasChanged('status') || $booking->status !== BookingStatus::Cancelled) {
            return;
        }

        $cases = CommissionCase::query()
            ->where('booking_id', $booking->getKey())
            ->whereIn('status', [CommissionCaseStatus::PendingReview->value, CommissionCaseStatus::OnHold->value])
            ->get();

        foreach ($cases as $case) {
            $case->forceFill([
                'status' => CommissionCaseStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => 'Booking cancelled.',
            ])->save();

            $case->recordEvent(
                CommissionCaseEventType::Cancelled,
                'Booking cancelled — commission case cancelled.',
                [],
                $booking->cancelled_by ? User::find($booking->cancelled_by) : null,
            );
        }

        $causer = $booking->cancelled_by ? User::find($booking->cancelled_by) : null;

        // Approved but nothing paid out → safe to auto-reverse (clawback 0).
        $approvedUnpaid = CommissionCase::query()
            ->where('booking_id', $booking->getKey())
            ->where('status', CommissionCaseStatus::Approved->value)
            ->where('paid_amount', '<=', 0)
            ->get();

        foreach ($approvedUnpaid as $case) {
            $case->forceFill([
                'status' => CommissionCaseStatus::Reversed->value,
                'reversed_at' => now(),
                'reversal_reason' => 'Booking cancelled.',
                'clawback_amount' => 0,
            ])->save();

            $case->recordEvent(
                CommissionCaseEventType::Reversed,
                'Booking cancelled — commission reversed (nothing paid out).',
                [],
                $causer,
            );
        }

        $needsManualReversal = CommissionCase::query()
            ->where('booking_id', $booking->getKey())
            ->whereIn('status', [CommissionCaseStatus::PartiallyPaid->value, CommissionCaseStatus::Paid->value])
            ->count();

        if ($cases->isNotEmpty() || $approvedUnpaid->isNotEmpty() || $needsManualReversal > 0) {
            Log::info('commission.booking_cancelled', [
                'booking_id' => $booking->getKey(),
                'cancelled_cases' => $cases->count(),
                'auto_reversed_cases' => $approvedUnpaid->count(),
                'cases_needing_manual_reversal' => $needsManualReversal,
            ]);
        }
    }
}
