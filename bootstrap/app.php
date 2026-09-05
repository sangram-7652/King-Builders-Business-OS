<?php

declare(strict_types=1);

use App\Exceptions\DomainException;
use App\Http\Controllers\Communication\WebhookController as CommunicationWebhookController;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsureCustomerPortalActive;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Readiness probe (M12) — deliberately outside the `web` group so it
            // starts no session and sets no cookie. `/up` stays the liveness
            // route; `/healthz` additionally verifies DB / cache / private disk.
            Route::get('/healthz', HealthController::class)
                ->name('healthz');

            // Communication delivery webhooks (M16.4 / F-M16-1) — outside `web`
            // so there is no session / CSRF; authenticity is the per-channel
            // HMAC signature verified in the controller. Rate-limited.
            Route::post('/webhooks/communication/{channel}', CommunicationWebhookController::class)
                ->middleware('throttle:120,1')
                ->name('communication.webhook');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind nginx / a load balancer that terminates TLS. The app container
        // publishes no port of its own, so trusting all forwarded headers is
        // safe; set TRUSTED_PROXIES to a comma-separated CIDR list for defence
        // in depth. '*' (the default) trusts the immediate calling IP.
        // This closure runs before the config service exists, so it must read
        // env() directly. Under `config:cache` the .env file is not reloaded at
        // boot, so set TRUSTED_PROXIES as a real container env var (see
        // docker-compose.prod.yml) if you need a value other than the '*'
        // default — which is safe here because the app container publishes no
        // port and is only reachable through nginx.
        $proxies = trim((string) env('TRUSTED_PROXIES', '*'));
        $middleware->trustProxies(
            at: ($proxies === '' || $proxies === '*') ? '*' : array_map('trim', explode(',', $proxies)),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'active' => EnsureUserIsActive::class,
            'customer.active' => EnsureCustomerPortalActive::class,
        ]);

        // Production security headers on every web response (self-disabling via
        // SECURITY_HEADERS_ENABLED=false).
        $middleware->web(append: [
            SecurityHeaders::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('portal', 'portal/*')
            ? route('portal.login')
            : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Expected business-rule failures render as their declared status for
        // JSON/AJAX clients (Livewire components already catch these to flash a
        // toast; this covers the non-Livewire request paths).
        $exceptions->render(function (DomainException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], $e->status());
            }

            return null;
        });
    })->create();
