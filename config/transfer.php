<?php

declare(strict_types=1);

/*
| ---------------------------------------------------------------------------
| Ownership transfer (M10)
| ---------------------------------------------------------------------------
| Business-rule thresholds for the transfer eligibility / financial-clearance
| engine. Config, not hard-coded.
*/

return [
    'financial' => [
        // Block approval while the booking has an outstanding balance (M7/M8),
        // unless a reviewer overrides with an explicit waiver reason.
        'block_on_outstanding' => (bool) env('TRANSFER_BLOCK_ON_OUTSTANDING', true),

        // Maximum outstanding that still counts as "clear".
        'max_outstanding' => (string) env('TRANSFER_MAX_OUTSTANDING', '0'),
    ],

    // Booking document codes (M9) that must be VERIFIED before a transfer that
    // moves ownership can be approved.
    'required_document_codes' => ['TRANSFER_APPLICATION', 'TRANSFER_CONSENT', 'TRANSFER_ID_PROOF'],

    'uploads' => [
        'max_kb' => (int) env('DOCUMENT_MAX_KB', 15360),
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'tiff', 'heic'],
    ],
];
