<?php

declare(strict_types=1);

/*
| ---------------------------------------------------------------------------
| Channel partners + attribution (M14)
| ---------------------------------------------------------------------------
| Business-rule toggles for the partner attribution engine. Config, not
| hard-coded. Commission-scheme / calculation settings land in config/commission.php
| in a later M14 phase.
*/

return [
    'attribution' => [
        // A partner must hold an ACTIVE authorisation for a booking's project
        // (M14.1 `partner_project_authorizations`) before they can be attributed
        // to a booking in that project.
        'require_project_authorization' => (bool) env('PARTNER_REQUIRE_PROJECT_AUTHORIZATION', true),

        // Allow changing a booking's attribution after it is CONFIRMED (still
        // blocked once a commission case has been generated — enforced in M14.4).
        'allow_edit_after_confirmation' => (bool) env('PARTNER_ALLOW_ATTRIBUTION_EDIT_AFTER_CONFIRMATION', true),
    ],
];
