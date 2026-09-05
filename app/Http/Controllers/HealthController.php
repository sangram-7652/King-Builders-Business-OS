<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Health\HealthProbe;
use Illuminate\Http\JsonResponse;

/**
 * Deep readiness probe (M12) — `GET /healthz` (unauthenticated, no session).
 *
 * Verifies the app can actually reach its runtime dependencies (database,
 * cache/Redis, the private document disk) rather than just "the PHP process
 * booted" — that is what Laravel's `/up` liveness route already covers.
 *
 * Information-minimal by design (see {@see HealthProbe}): a load balancer can
 * call it, and "the database is unreachable" is not useful to an attacker.
 */
class HealthController extends Controller
{
    public function __invoke(HealthProbe $probe): JsonResponse
    {
        $checks = $probe->run();
        $ok = $probe->passing($checks);

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => $checks,
        ], $ok ? 200 : 503);
    }
}
