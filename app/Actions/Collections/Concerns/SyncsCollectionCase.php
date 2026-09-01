<?php

declare(strict_types=1);

namespace App\Actions\Collections\Concerns;

use App\Enums\CollectionActivityType;
use App\Enums\CollectionCaseStatus;
use App\Models\CollectionCase;
use App\Services\Collections\CollectionPrioritizer;
use App\Services\Payments\PaymentLedger;

/**
 * Re-derives a collection case's priority, resolution state and
 * `next_follow_up_at` from M7 truth + the case's own follow-ups. Call this at
 * the end of every collection action, inside its transaction.
 */
trait SyncsCollectionCase
{
    protected function syncCase(CollectionCase $case): CollectionCase
    {
        $case->loadMissing('booking');
        $ledger = app(PaymentLedger::class);
        $prioritizer = app(CollectionPrioritizer::class);

        $outstanding = $ledger->bookingOutstanding($case->booking);

        // --- Auto resolve / reopen -------------------------------------
        if (! $outstanding->isPositive() && $case->status !== CollectionCaseStatus::Resolved) {
            $case->forceFill(['status' => CollectionCaseStatus::Resolved, 'resolved_at' => now()])->save();
            $case->recordActivity(CollectionActivityType::CaseResolved, 'Booking outstanding cleared — case resolved.');
        } elseif ($outstanding->isPositive() && $case->status === CollectionCaseStatus::Resolved) {
            $case->forceFill(['status' => CollectionCaseStatus::Open, 'resolved_at' => null])->save();
            $case->recordActivity(CollectionActivityType::CaseReopened, 'Outstanding returned — case reopened.');
        }

        // --- Priority -------------------------------------------------
        if ($case->status !== CollectionCaseStatus::Resolved) {
            $newPriority = $prioritizer->priorityFor($case->booking);

            if ($case->priority !== $newPriority) {
                $old = $case->priority;
                $case->forceFill(['priority' => $newPriority])->save();
                $case->recordActivity(
                    CollectionActivityType::PriorityChanged,
                    "Priority {$old->label()} → {$newPriority->label()}.",
                    ['from' => $old->value, 'to' => $newPriority->value],
                );
            }
        }

        // --- Denormalised follow-up pointer --------------------------
        $case->forceFill([
            'next_follow_up_at' => $case->pendingFollowUps()->min('follow_up_at'),
        ])->save();

        return $case->refresh();
    }
}
