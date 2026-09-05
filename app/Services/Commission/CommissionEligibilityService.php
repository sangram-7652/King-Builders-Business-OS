<?php

declare(strict_types=1);

namespace App\Services\Commission;

use App\Actions\Commission\RecordCommissionPayout;
use App\Models\Booking;
use App\Models\Partner;
use App\Services\Payments\PaymentLedger;
use App\Support\Money;

/**
 * Decides whether a commission case may be generated for a (booking, partner)
 * pair (M14.4). Read-only — consumes M6 / M7 truth, never mutates anything.
 *
 * Rules:
 *   - the booking must be CONFIRMED (M6 lifecycle)
 *   - a PUBLISHED commission scheme + rule must resolve for the partner
 *   - config `commission.eligibility.min_collected_percent` — the booking must
 *     have collected at least this % of its value before commission is earned
 *     (default 0 = earned at confirmation)
 *
 * @phpstan-type Result array{eligible: bool, reason: string}
 */
class CommissionEligibilityService
{
    public function __construct(
        private readonly CommissionSchemeResolver $schemes,
        private readonly PaymentLedger $ledger,
    ) {}

    /**
     * @return array{eligible: bool, reason: string}
     */
    public function evaluate(Booking $booking, Partner $partner): array
    {
        if (! $booking->isConfirmed()) {
            return $this->fail('The booking is not confirmed.');
        }

        if (! $partner->status->isOpen()) {
            return $this->fail("The partner is {$partner->status->label()}.");
        }

        if ($this->schemes->resolveRule($partner, $booking->project_id) === null) {
            return $this->fail('No published commission scheme resolves for this partner.');
        }

        if (($shortfall = $this->collectionShortfallReason($booking)) !== null) {
            return $this->fail($shortfall);
        }

        return ['eligible' => true, 'reason' => 'Eligible.'];
    }

    /**
     * The configured minimum-collected-percent gate, evaluated against CURRENT
     * M7 truth. Shared by case generation and by
     * {@see RecordCommissionPayout} (F-M14-3) so a
     * reversed payment / bounced cheque since generation still blocks a payout.
     *
     * Returns null when the gate passes (or is disabled), else a reason string.
     */
    public function collectionShortfallReason(Booking $booking): ?string
    {
        $minPercent = (float) config('commission.eligibility.min_collected_percent', 0);

        if ($minPercent <= 0) {
            return null;
        }

        $value = Money::of($booking->final_amount);
        $collected = $this->ledger->bookingPaid($booking);
        $required = Money::of((string) $minPercent)->percentageOf($value);

        if ($collected->lessThan($required)) {
            return "Only ₹{$collected->store()} collected; ₹{$required->store()} ({$minPercent}%) of the booking value is required.";
        }

        return null;
    }

    /**
     * @return array{eligible: false, reason: string}
     */
    private function fail(string $reason): array
    {
        return ['eligible' => false, 'reason' => $reason];
    }
}
