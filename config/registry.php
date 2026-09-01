<?php

declare(strict_types=1);

/*
| ---------------------------------------------------------------------------
| Registry & documentation (M9)
| ---------------------------------------------------------------------------
| Business-rule thresholds for the registry eligibility engine. A later
| milestone may move these to a master table; for now they are config so a
| tenant can tune them without a code change.
*/

return [
    'eligibility' => [
        // Minimum % of the booking's final amount that must be collected (M7).
        'required_paid_percent' => (float) env('REGISTRY_REQUIRED_PAID_PERCENT', 90),

        // Whether an overdue balance (M8) blocks registry.
        'block_on_overdue' => (bool) env('REGISTRY_BLOCK_ON_OVERDUE', true),

        // Whether every REQUIRED document (buyer + booking) must be VERIFIED
        // (true) or merely received (false).
        'require_documents_verified' => true,

        // Whether the agreement must be at least SIGNED.
        'require_agreement_signed' => true,
    ],

    // Upload constraints (also enforced in the Livewire validation rules).
    'uploads' => [
        'max_kb' => (int) env('DOCUMENT_MAX_KB', 15360), // 15 MB
        'mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'tiff', 'heic'],
    ],
];
