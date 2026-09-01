<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Trivial job used to verify the queue pipeline end-to-end
 * (`php artisan queue:work` in the `queue` container).
 *
 * Not part of any business workflow — safe to dispatch at any time:
 *   App\Jobs\HealthPingJob::dispatch('manual');
 */
class HealthPingJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $source = 'unknown') {}

    public function handle(): void
    {
        Log::info('queue.health_ping', [
            'source' => $this->source,
            'worker_pid' => getmypid(),
            'at' => now()->toIso8601String(),
        ]);
    }
}
