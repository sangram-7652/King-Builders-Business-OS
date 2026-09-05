<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Production security response headers (M12).
 *
 * Applied to the whole `web` group. Deliberately conservative so it never
 * breaks the Livewire / Alpine / Vite admin UI:
 *
 *  - HSTS is only emitted over a genuine HTTPS request in production, so it
 *    can never poison `http://localhost` during development.
 *  - The CSP allows `'unsafe-inline'` (Livewire snapshot scripts, Alpine
 *    `x-*` bindings, Tailwind v4 inline styles) and `'unsafe-eval'` (Alpine
 *    expression evaluation). It still blocks the high-value XSS vector —
 *    loading script/style/frame from an external origin — and is fully
 *    overridable via `SECURITY_HEADERS_CSP` for a stricter deployment.
 *  - Everything is a no-op when `security.headers_enabled` is false, so ops can
 *    disable it via config without a code deploy if a header is found to break
 *    something. All settings come from `config/security.php` (never a runtime
 *    `env()` call) so `php artisan config:cache` is safe (F-M12-3 / S-4).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! (bool) config('security.headers_enabled', true)) {
            return $response;
        }

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'accelerometer=(), autoplay=(), camera=(), display-capture=(), '
                .'encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), '
                .'magnetometer=(), microphone=(), midi=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ];

        $csp = (string) config('security.csp', '');
        if ($csp !== '' && $csp !== 'off') {
            $headers['Content-Security-Policy'] = $csp;
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        // HSTS: only ever over real HTTPS in production.
        if ($request->secure() && app()->environment('production')) {
            $response->headers->set(
                'Strict-Transport-Security',
                (string) config('security.hsts', 'max-age=31536000; includeSubDomains'),
            );
        }

        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        return $response;
    }
}
