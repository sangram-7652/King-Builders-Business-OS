<?php

declare(strict_types=1);

/*
| ---------------------------------------------------------------------------
| Commission schemes + calculation (M14.3+)
| ---------------------------------------------------------------------------
| Config, not hard-coded. M14.3 covers scheme definition; the eligibility /
| calculation / payout settings (M14.4–M14.5) are added here as those phases
| land. Commission is operational tracking only — never an accounting ledger,
| and never GST / TDS.
*/

return [
    'schemes' => [
        // The default basis for a new scheme when the form leaves it unset.
        'default_basis' => 'booking_value', // App\Enums\CommissionBasis

        // Default slab interpretation for a new slab rule.
        'default_slab_mode' => 'whole', // App\Enums\SlabMode
    ],

    'eligibility' => [
        // The booking must have collected at least this percentage of its value
        // before a partner's commission is treated as earned. 0 = earned at
        // booking confirmation.
        'min_collected_percent' => (float) env('COMMISSION_MIN_COLLECTED_PERCENT', 0),
    ],
];
