<?php

declare(strict_types=1);

namespace App\Support\Collections;

use App\Support\Money;

/**
 * Immutable snapshot of the collection position across the whole portfolio
 * (M8). Every money field is sourced from the M7 ledger.
 */
final class CollectionDashboardData
{
    /**
     * @param  array<string, array{amount: Money, bookings: int, customers: int}>  $aging  keyed by AgingBucket value
     */
    public function __construct(
        public readonly Money $totalReceivable,
        public readonly Money $totalCollected,
        public readonly Money $totalOutstanding,
        public readonly Money $totalOverdue,
        public readonly Money $dueToday,
        public readonly Money $dueThisWeek,
        public readonly Money $collectedToday,
        public readonly Money $expectedToday,
        public readonly array $aging,
        public readonly int $openFollowUps,
        public readonly int $promisesDue,
        public readonly int $brokenPromises,
        public readonly int $pendingCheques,
        public readonly int $bouncedCheques,
        public readonly int $openCases,
        public readonly int $unassignedCases,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_receivable' => $this->totalReceivable->store(),
            'total_collected' => $this->totalCollected->store(),
            'total_outstanding' => $this->totalOutstanding->store(),
            'total_overdue' => $this->totalOverdue->store(),
            'due_today' => $this->dueToday->store(),
            'due_this_week' => $this->dueThisWeek->store(),
            'collected_today' => $this->collectedToday->store(),
            'expected_today' => $this->expectedToday->store(),
            'aging' => collect($this->aging)->map(fn ($b) => [
                'amount' => $b['amount']->store(),
                'bookings' => $b['bookings'],
                'customers' => $b['customers'],
            ])->all(),
            'open_follow_ups' => $this->openFollowUps,
            'promises_due' => $this->promisesDue,
            'broken_promises' => $this->brokenPromises,
            'pending_cheques' => $this->pendingCheques,
            'bounced_cheques' => $this->bouncedCheques,
            'open_cases' => $this->openCases,
            'unassigned_cases' => $this->unassignedCases,
        ];
    }
}
