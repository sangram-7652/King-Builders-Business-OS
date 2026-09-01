<?php

declare(strict_types=1);

namespace App\Actions\Plots;

use App\Exceptions\DomainException;
use App\Models\Plot;
use Illuminate\Support\Facades\Log;

/**
 * Returns every plot whose hold has lapsed (`hold_expires_at <= now` and still
 * HOLD) back to AVAILABLE.
 *
 * Idempotent & safe to run repeatedly / concurrently:
 *  - each plot is re-locked and re-checked inside its own transaction, so a plot
 *    that has meanwhile been BOOKED (or released, or transferred) is skipped;
 *  - running it twice in a row does nothing the second time.
 */
class ExpirePlotHoldsAction
{
    public function __construct(private readonly ReleasePlotHoldAction $release) {}

    public function handle(): int
    {
        $expired = 0;

        Plot::query()
            ->holdExpired()
            ->orderBy('id')
            ->chunkById(200, function ($plots) use (&$expired): void {
                foreach ($plots as $plot) {
                    try {
                        $this->release->handle($plot->id, context: 'expiry');
                        $expired++;
                    } catch (DomainException) {
                        // The plot left HOLD between the scan and the lock — fine, skip it.
                    }
                }
            });

        if ($expired > 0) {
            Log::info('plot.holds_expired', ['count' => $expired]);
        }

        return $expired;
    }
}
