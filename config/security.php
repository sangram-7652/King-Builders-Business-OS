<?php

declare(strict_types=1);

/*
| ---------------------------------------------------------------------------
| Security response headers (M12, finding F-M12-3 / S-4)
| ---------------------------------------------------------------------------
| Consumed by App\Http\Middleware\SecurityHeaders. These live in config (not
| read via env() at request time) so `php artisan config:cache` is safe — the
| "disable instantly" escape hatch keeps working from a cached config as long
| as the operator runs `config:clear && config:cache` after changing .env,
| which is the standard deploy step.
*/

return [
    // Master switch. SECURITY_HEADERS_ENABLED=false makes the middleware a no-op.
    'headers_enabled' => (bool) env('SECURITY_HEADERS_ENABLED', true),

    // Content-Security-Policy. Deliberately allows 'unsafe-inline' / 'unsafe-eval'
    // for the Livewire + Alpine + Tailwind-v4 admin UI, but still blocks loading
    // script / style / frame from an external origin. Set to 'off' to omit the
    // header, or override with a stricter policy per deployment.
    'csp' => env('SECURITY_HEADERS_CSP', "default-src 'self'; "
        ."script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
        ."style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: blob:; "
        ."font-src 'self' data:; "
        ."connect-src 'self'; "
        ."media-src 'self'; "
        ."object-src 'none'; "
        ."frame-ancestors 'self'; "
        ."base-uri 'self'; "
        .'form-action \'self\''),

    // HSTS value — only ever emitted over a genuine HTTPS request in production.
    'hsts' => env('SECURITY_HSTS', 'max-age=31536000; includeSubDomains'),

    // NOTE: reverse-proxy trust (TRUSTED_PROXIES) is read in bootstrap/app.php,
    // which runs before the config service exists and so cannot live here. It
    // is set as a real container env var in docker-compose.prod.yml so it
    // survives `config:cache`.
];
