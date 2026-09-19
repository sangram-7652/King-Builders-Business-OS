<?php

declare(strict_types=1);

namespace App\Services\Possession;

use App\Enums\BookingStatus;
use App\Enums\PlotStatus;
use App\Enums\RegistryCaseStatus;
use App\Models\Booking;
use App\Services\Documents\DocumentChecklistService;
use App\Services\Payments\PaymentLedger;
use App\Support\Money;
use App\Support\Registry\EligibilityResult;

/**
 * The single place that decides whether a booking may proceed to possession
 * (M10). It CONSUMES M4 / M6 / M7 / M8 / M9 state — it never mutates any of it,
 * and this logic lives nowhere else (no duplication in Livewire / Blade).
 *
 *   - booking is CONFIRMED
 *   - plot is valid and active
 *   - the M9 registry case is COMPLETED
 *   - required booking documents are verified (M9)
 *   - financial prerequisite: ≥ N% collected (M7)
 *
 * Clearances + site inspection are handled downstream by the
 * {@see PossessionChecklistService} once a case has been scheduled.
 */
class PossessionEligibilityService
{
    public function __construct(
        private readonly PaymentLedger $ledger,
        private readonly DocumentChecklistService $checklist,
    ) {}

    public function evaluate(Booking $booking): EligibilityResult
    {
        $cfg = config('possession.eligibility');
        $checks = [];

        $confirmed = $booking->status === BookingStatus::Confirmed;
        $checks[] = $this->check('booking_confirmed', 'Booking confirmed', $confirmed,
            $confirmed ? null : "Booking is {$booking->status->label()}.");

        $plot = $booking->relationLoaded('plot') ? $booking->plot : $booking->plot()->first();
        $plotOk = $plot !== null && $plot->is_active
            && in_array($plot->status, [PlotStatus::Booked, PlotStatus::Sold, PlotStatus::PossessionCompleted], true);
        $checks[] = $this->check('plot_valid', 'Plot valid and active', $plotOk,
            $plotOk ? null : 'Plot is not active / booked.');

        if ($cfg['require_registry_completed']) {
            $registry = $booking->relationLoaded('registryCase') ? $booking->registryCase : $booking->registryCase()->first();
            $registryOk = $registry !== null && $registry->status === RegistryCaseStatus::Completed;
            $checks[] = $this->check('registry_completed', 'Registry completed', $registryOk,
                $registryOk ? null : ($registry === null ? 'No registry case.' : "Registry is {$registry->status->label()}."));
        }

        if ($cfg['require_documents_verified']) {
            $bookingChecklist = $this->checklist->forBooking($booking);
            $checks[] = $this->check('documents_verified', 'Required booking documents verified',
                $bookingChecklist->isComplete(),
                $bookingChecklist->isComplete() ? null
                    : "{$bookingChecklist->verifiedCount}/{$bookingChecklist->requiredCount} booking documents verified.");
        }

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
