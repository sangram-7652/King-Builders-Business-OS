<?php

declare(strict_types=1);

namespace App\Services\Registry;

use App\Enums\AgreementStatus;
use App\Enums\BookingStatus;
use App\Enums\PlotStatus;
use App\Models\Booking;
use App\Services\Documents\DocumentChecklistService;
use App\Services\Payments\PaymentLedger;
use App\Support\Money;
use App\Support\Registry\EligibilityResult;

/**
 * The single place that decides whether a booking may proceed to registry (M9).
 * It CONSUMES M4 / M6 / M7 / M8 / document state — it never mutates any of it,
 * and this logic lives nowhere else (no duplication in Livewire / Blade).
 *
 *   - booking is CONFIRMED
 *   - plot is valid, active and BOOKED
 *   - required buyer KYC documents are verified
 *   - required booking documents are verified
 *   - the agreement is at least SIGNED, only if `registry.eligibility.
 *     require_agreement_signed` is enabled — off by default since the
 *     dedicated Agreement workflow was removed; historical Agreement rows
 *     are still read here when the flag is on
 *   - financial prerequisite: ≥ N% collected (M7)
 */
class RegistryEligibilityService
{
    public function __construct(
        private readonly PaymentLedger $ledger,
        private readonly DocumentChecklistService $checklist,
    ) {}

    public function evaluate(Booking $booking): EligibilityResult
    {
        $cfg = config('registry.eligibility');
        $checks = [];

        // --- Booking -------------------------------------------------
        $checks[] = $this->check('booking_confirmed', 'Booking confirmed',
            $booking->status === BookingStatus::Confirmed,
            $booking->status === BookingStatus::Confirmed ? null : "Booking is {$booking->status->label()}.");

        // --- Plot ---------------------------------------------------
        $plot = $booking->relationLoaded('plot') ? $booking->plot : $booking->plot()->first();
        $plotOk = $plot !== null && $plot->is_active && $plot->status === PlotStatus::Booked;
        $checks[] = $this->check('plot_valid', 'Plot valid and booked', $plotOk,
            $plotOk ? null : 'Plot is not active / booked.');

        // --- Buyer documents -----------------------------------------
        $buyer = $booking->relationLoaded('primaryBookingBuyer')
            ? $booking->primaryBookingBuyer?->buyer
            : $booking->primaryBookingBuyer()->with('buyer')->first()?->buyer;

        if ($buyer !== null) {
            $buyerChecklist = $this->checklist->forBuyer($buyer);
            $checks[] = $this->check('buyer_documents', 'Required buyer documents verified',
                $buyerChecklist->isComplete(),
                $buyerChecklist->isComplete() ? null
                    : "{$buyerChecklist->verifiedCount}/{$buyerChecklist->requiredCount} buyer documents verified.");
        } else {
            $checks[] = $this->check('buyer_documents', 'Required buyer documents verified', false, 'No primary buyer.');
        }

        // --- Booking documents -------------------------------------
        $bookingChecklist = $this->checklist->forBooking($booking);
        $checks[] = $this->check('booking_documents', 'Required booking documents verified',
            $bookingChecklist->isComplete(),
            $bookingChecklist->isComplete() ? null
                : "{$bookingChecklist->verifiedCount}/{$bookingChecklist->requiredCount} booking documents verified.");

        // --- Agreement --------------------------------------------
        if ($cfg['require_agreement_signed']) {
            $agreement = $booking->relationLoaded('agreement') ? $booking->agreement : $booking->agreement()->first();
            $signed = $agreement !== null && in_array($agreement->status, [AgreementStatus::Signed, AgreementStatus::Approved], true);
            $checks[] = $this->check('agreement_signed', 'Agreement signed', $signed,
                $signed ? null : ($agreement === null ? 'No agreement.' : "Agreement is {$agreement->status->label()}."));
        }

        // --- Financial -------------------------------------------
        $final = Money::of($booking->final_amount);
        $paid = $this->ledger->bookingPaid($booking);
        $percentPaid = $final->isPositive()
            ? (float) bcdiv(bcmul($paid->store(), '100', 4), $final->store(), 2)
            : 100.0;
        $paidOk = $percentPaid + 0.001 >= (float) $cfg['required_paid_percent'];
        $checks[] = $this->check('sufficient_payment',
            "At least {$cfg['required_paid_percent']}% collected", $paidOk,
            $paidOk ? null : sprintf('%.2f%% collected.', $percentPaid));

        $eligible = ! in_array(false, array_column($checks, 'passed'), true);

        return new EligibilityResult(eligible: $eligible, checks: $checks);
    }

    /**
     * @return array{key: string, label: string, passed: bool, detail: string|null}
     */
    private function check(string $key, string $label, bool $passed, ?string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'passed' => $passed, 'detail' => $detail];
    }
}
