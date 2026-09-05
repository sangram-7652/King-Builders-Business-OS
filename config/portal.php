<?php

declare(strict_types=1);

/*
| ---------------------------------------------------------------------------
| Customer self-service portal (M15)
| ---------------------------------------------------------------------------
| Read-only window onto a customer's own bookings / payments / documents.
| No payment gateway, no external messaging. Config, not hard-coded.
*/

return [
    // How long an activation / reset link stays valid.
    'invite_ttl_hours' => (int) env('PORTAL_INVITE_TTL_HOURS', 168),   // 7 days
    'reset_ttl_hours' => (int) env('PORTAL_RESET_TTL_HOURS', 24),

    // Sign-in throttling (per email + IP).
    'login_max_attempts' => (int) env('PORTAL_LOGIN_MAX_ATTEMPTS', 5),
    'login_decay_seconds' => (int) env('PORTAL_LOGIN_DECAY_SECONDS', 60),

    // Pagination defaults for portal lists.
    'per_page' => (int) env('PORTAL_PER_PAGE', 15),
];
