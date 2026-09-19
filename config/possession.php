<?php

declare(strict_types=1);

/*
| ---------------------------------------------------------------------------
| Possession + handover (M10)
| ---------------------------------------------------------------------------
| Business-rule thresholds for the possession eligibility + checklist engines.
| Config (not hard-coded) so a tenant can tune them without a code change; a
| later milestone may promote them to a master table.
*/

return [
    'eligibility' => [
        // Booking must be confirmed (always true, listed for completeness).
        'require_booking_confirmed' => true,

        // The M9 registry case must be COMPLETED.
        'require_registry_completed' => (bool) env('POSSESSION_REQUIRE_REGISTRY', true),

        // Every REQUIRED booking document must be VERIFIED (M9 checklist).
        'require_documents_verified' => (bool) env('POSSESSION_REQUIRE_DOCUMENTS', true),

        // Minimum % of the booking's final amount that must be collected (M7).
        'required_paid_percent' => (float) env('POSSESSION_REQUIRED_PAID_PERCENT', 100),

        // Every clearance category must be CLEARED / WAIVED.
        'require_all_clearances' => true,

        // The latest site inspection must be PASSED.
        'require_inspection_passed' => true,
    ],

    // Which clearance categories a case is opened with, and which are required
    // for the checklist to be complete.
    'clearances' => [
        'financial' => ['required' => true],
        'document' => ['required' => true],
        'legal' => ['required' => true],
        'site' => ['required' => true],
    ],

    // Financial clearance guard — a FINANCIAL clearance cannot be marked CLEARED
    // while the booking still owes money, unless it is explicitly WAIVED.
    'financial_clearance' => [
        'max_outstanding' => (string) env('POSSESSION_MAX_OUTSTANDING', '0'),
    ],

    'uploads' => [
        'max_kb' => (int) env('DOCUMENT_MAX_KB', 15360),
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'tiff', 'heic'],
    ],
];
