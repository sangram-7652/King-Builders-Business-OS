<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Enums\CollectionPriority;
use App\Enums\PromiseStatus;
use App\Models\Booking;
use App\Models\ChequeBounce;
use App\Models\PaymentPromise;
use App\Services\Payments\PaymentLedger;
use Illuminate\Support\Carbon;

/**
 * Deterministic, rule-based collection priority (M8). NOT an AI / scoring model
 * — a small, explainable point tally driven by `config/collections.php`.
 *
 *   overdue age  (worst installment)  → up to +3
 *   overdue amount                     → up to +2
 *   any broken promise                 → +2
 *   any cheque bounce                  → +1
 *
 *   score >= 6 CRITICAL · >= 4 HIGH · >= 2 MEDIUM · else LOW
 */
class CollectionPrioritizer
{
    public function __construct(
        private readonly PaymentLedger $ledger,
        private readonly AgingCalculator $aging,
    ) {}

    public function priorityFor(Booking $booking, ?Carbon $asOf = null): CollectionPriority
    {
        return CollectionPriority::fromScore($this->scoreFor($booking, $asOf));
    }

    public function scoreFor(Booking $booking, ?Carbon $asOf = null): int
    {
        $cfg = config('collections.priority');
        $score = 0;

        $maxDays = $this->aging->maxDaysOverdue($booking, $asOf);
        $score += match (true) {
            $maxDays > $cfg['days_overdue']['critical_over'] => 3,
            $maxDays > $cfg['days_overdue']['high_over'] => 2,
            $maxDays > $cfg['days_overdue']['medium_over'] => 1,
            $maxDays > 0 => 1,
            default => 0,
        };

        $overdue = (float) $this->ledger->bookingOverdue($booking, $asOf)->store();
        $score += match (true) {
            $overdue >= $cfg['overdue_amount']['high_over'] => 2,
            $overdue >= $cfg['overdue_amount']['medium_over'] => 1,
            default => 0,
        };

        $brokenPromises = PaymentPromise::query()
            ->where('booking_id', $booking->getKey())
            ->where('status', PromiseStatus::Broken->value)
            ->exists();
        $score += $brokenPromises ? (int) $cfg['broken_promise_points'] : 0;

        $bounces = ChequeBounce::query()->where('booking_id', $booking->getKey())->exists();
        $score += $bounces ? (int) $cfg['cheque_bounce_points'] : 0;

        return $score;
    }
}
