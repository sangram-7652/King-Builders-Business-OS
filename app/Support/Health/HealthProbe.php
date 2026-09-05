<?php

declare(strict_types=1);

namespace App\Support\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The single readiness check (M12), shared by the `GET /healthz` controller
 * (for load balancers) and the `php artisan health:check` command (the Docker
 * HEALTHCHECK for the app / queue / scheduler containers).
 *
 * Verifies the app can reach every runtime dependency it needs to serve a
 * request. Returns only `ok` / `fail` per named component — never a host,
 * credential, driver, version, path or exception message.
 */
final class HealthProbe
{
    /**
     * @return array<string, 'ok'|'fail'>
     */
    public function run(): array
    {
        return [
            'database' => $this->probe(fn () => DB::connection()->select('select 1')),
            'cache' => $this->probe(function () {
                $token = Str::random(24);
                Cache::store()->put('healthz', $token, 10);

                return Cache::store()->get('healthz') === $token;
            }),
            'storage' => $this->probe(function () {
                // The private document disk must be writable (M9/M10 store here).
                $disk = Storage::disk('documents');
                $key = '.healthz-'.Str::random(8);
                $disk->put($key, 'ok');
                $ok = $disk->get($key) === 'ok';
                $disk->delete($key);

                return $ok;
            }),
        ];
    }

    /**
     * @param  array<string, 'ok'|'fail'>|null  $result
     */
    public function passing(?array $result = null): bool
    {
        return ! in_array('fail', $result ?? $this->run(), true);
    }

    private function probe(callable $check): string
    {
        try {
            return $check() === false ? 'fail' : 'ok';
        } catch (Throwable) {
            return 'fail';
        }
    }
}
