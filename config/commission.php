<?php

declare(strict_types=1);

/*
| ---------------------------------------------------------------------------
| Commission (M14.4+)
| ---------------------------------------------------------------------------
| Config, not hard-coded. Commission is a flat Booking final_amount ×
| Partner.commission_percentage — no schemes / rules / slabs. Payouts are
| operational tracking only — never an accounting ledger, and never GST / TDS.
| The promoter's advance ledger (Promoter Advance) is the one place real
| financial history is kept — see App\Services\Commission\PromoterLedgerService.
*/

return [
    'eligibility' => [
        // The booking must have collected at least this percentage of its value
        // before a partner's commission is treated as earned. 0 = earned at
        // booking confirmation.
        'min_collected_percent' => (float) env('COMMISSION_MIN_COLLECTED_PERCENT', 0),
    ],
];
