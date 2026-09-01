<?php

declare(strict_types=1);

namespace App\Actions\Collections;

use App\Actions\Collections\Concerns\SyncsCollectionCase;
use App\Enums\BookingStatus;
use App\Enums\CollectionActivityType;
use App\Models\Booking;
use App\Services\Collections\AgingCalculator;
use App\Services\Payments\PaymentLedger;

/**
 * Scheduled sweep (M8): opens a collection case for every confirmed booking
 * that has an OVERDUE balance, re-evaluates promises, and re-syncs priority /
 * resolution for every case. Idempotent.
 */
class RefreshCollectionQueueAction
{
    use SyncsCollectionCase;

    public function __construct(
        private readonly EnsureCollectionCaseAction $ensureCase,
        private readonly EvaluatePaymentPromisesAction $evaluatePromises,
        private readonly PaymentLedger $ledger,
        private readonly AgingCalculator $aging,
    ) {}

    /**
     * @return array{cases_opened: int, cases_synced: int}
     */
    public function handle(): array
    {
        $opened = 0;
        $synced = 0;

        Booking::query()
            ->where('status', BookingStatus::Confirmed->value)
            ->with(['activePaymentPlan.installments', 'collectionCase'])
            ->chunkById(200, function ($bookings) use (&$opened, &$synced): void {
                foreach ($bookings as $booking) {
                    $hasOverdue = $this->ledger->bookingOverdue($booking)->isPositive();
                    $case = $booking->collectionCase;

                    if ($case === null && $hasOverdue) {
                        $case = $this->ensureCase->handle($booking);
                        $case->setRelation('booking', $booking);

                        $days = $this->aging->maxDaysOverdue($booking);
                        $case->recordActivity(
                            CollectionActivityType::InstallmentOverdue,
                            "Installment overdue by up to {$days} day(s).",
                            ['days_overdue' => $days],
                        );
                        $opened++;
                    }

                    if ($case === null) {
                        continue;
                    }

                    $this->evaluatePromises->handle($booking);
                    $case->setRelation('booking', $booking);
                    $this->syncCase($case);
                    $synced++;
                }
            });

        return ['cases_opened' => $opened, 'cases_synced' => $synced];
    }
}
