<?php

declare(strict_types=1);

/*
| ---------------------------------------------------------------------------
| Collection management (M8)
| ---------------------------------------------------------------------------
| Deterministic, rule-based priority thresholds — NOT a scoring model. A later
| milestone may move these to a master table; for now they are config so a
| tenant can tune them without a code change.
*/

return [
    // Priority scoring — see App\Services\Collections\CollectionPrioritizer.
    'priority' => [
        // Points added by the worst overdue installment's age (days).
        'days_overdue' => [
            'critical_over' => 180,  // +3
            'high_over' => 90,       // +2
            'medium_over' => 30,     // +1
            // any overdue at all      +1
        ],
        // Points added by the overdue amount (₹).
        'overdue_amount' => [
            'high_over' => 1000000,  // +2
            'medium_over' => 300000, // +1
        ],
        'broken_promise_points' => 2,
        'cheque_bounce_points' => 1,
    ],

    // How many days out an installment is "due this week" on the dashboard.
    'due_soon_days' => 7,
];
