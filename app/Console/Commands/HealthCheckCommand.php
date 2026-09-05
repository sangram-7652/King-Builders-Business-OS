<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Health\HealthProbe;
use Illuminate\Console\Command;

/**
 * `php artisan health:check` (M12) — the Docker HEALTHCHECK for the app / queue
 * / scheduler containers (which run php-fpm or a worker, not an HTTP server, so
 * they cannot be probed over HTTP). Exit 0 = healthy, 1 = degraded.
 */
class HealthCheckCommand extends Command
{
    protected $signature = 'health:check {--json : Print the per-component result as JSON}';

    protected $description = 'Verify the app can reach its database, cache and private document storage';

    public function handle(HealthProbe $probe): int
    {
        $checks = $probe->run();
        $ok = $probe->passing($checks);

        if ($this->option('json')) {
            $this->line((string) json_encode(['status' => $ok ? 'ok' : 'degraded', 'checks' => $checks]));
        } else {
            foreach ($checks as $name => $state) {
                $this->line(sprintf('%-10s %s', $name, $state === 'ok' ? '<info>ok</info>' : '<error>FAIL</error>'));
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
