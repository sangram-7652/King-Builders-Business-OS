<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Commission\GenerateCommissionCases;
use App\Enums\BookingStatus;
use App\Enums\CommissionCaseEventType;
use App\Enums\CommissionCaseStatus;
use App\Exceptions\DomainException;
use App\Models\Booking;
use App\Models\CommissionCase;
use App\Models\User;
use App\Services\Commission\PromoterLedgerService;
use Illuminate\Support\Facades\Log;

/**
 * Keeps commission cases in step with the M6 booking lifecycle (M14.4).
 *
 * When a booking becomes CONFIRMED:
 *   - if it already has an active promoter attribution, the commission case
 *     is generated automatically (see {@see autoGenerateCommission()}) — no
 *     manual "Generate" click is required for the normal workflow. A booking
 *     with no promoter is left untouched; confirmation is never blocked by
 *     this.
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
        if (! $booking->wasChanged('status')) {
            return;
        }

        if ($booking->status === BookingStatus::Confirmed) {
            $this->autoGenerateCommission($booking);

            return;
        }

        if ($booking->status !== BookingStatus::Cancelled) {
            return;
        }

        $cases = CommissionCase::query()
            ->where('booking_id', $booking->getKey())
            ->whereIn('status', [CommissionCaseStatus::PendingReview->value, CommissionCaseStatus::OnHold->value])
            ->get();

        $ledger = app(PromoterLedgerService::class);
        $causer = $booking->cancelled_by ? User::find($booking->cancelled_by) : null;

        foreach ($cases as $case) {
            $ledger->reverseCaseAdjustment($case, $causer, 'Booking cancelled — commission cancelled.');

            $case->forceFill([
                'status' => CommissionCaseStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => 'Booking cancelled.',
            ])->save();

            $case->recordEvent(
                CommissionCaseEventType::Cancelled,
                'Booking cancelled — commission case cancelled.',
                [],
                $causer,
            );
        }

        // Approved but nothing paid out → safe to auto-reverse (clawback 0).
        $approvedUnpaid = CommissionCase::query()
            ->where('booking_id', $booking->getKey())
            ->where('status', CommissionCaseStatus::Approved->value)
            ->where('paid_amount', '<=', 0)
            ->get();

        foreach ($approvedUnpaid as $case) {
            $ledger->reverseCaseAdjustment($case, $causer, 'Booking cancelled — commission reversed.');

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

    /**
     * Booking confirmed + already has an active promoter attribution →
     * generate the commission case immediately. GenerateCommissionCases is
     * idempotent (unique (booking_id, partner_id) + row locks), so this is
     * always safe even if called more than once. A failure here is logged
     * and swallowed — it must never roll back the booking confirmation that
     * is already committing in the same transaction.
     */
    private function autoGenerateCommission(Booking $booking): void
    {
        $attribution = $booking->partnerAttributions()->first();

        if ($attribution === null) {
            return;
        }

        $actor = $booking->confirmed_by ? User::find($booking->confirmed_by) : null;

        if ($actor === null) {
            Log::error('commission.auto_generate_skipped_no_actor', ['booking_id' => $booking->getKey()]);

            return;
        }

        try {
            app(GenerateCommissionCases::class)->handle($booking, $actor);
        } catch (DomainException $e) {
            Log::error('commission.auto_generate_failed', [
                'booking_id' => $booking->getKey(),
                'partner_id' => $attribution->partner_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
