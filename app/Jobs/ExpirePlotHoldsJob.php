<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Plots\ExpirePlotHoldsAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued worker that releases lapsed plot holds. Dispatched by the scheduler
 * every few minutes (see routes/console.php).
 *
 * `ShouldBeUnique` + the per-plot re-lock/re-check in ExpirePlotHoldsAction keep
 * it safe to run while another copy is still in flight.
 */
class ExpirePlotHoldsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public function handle(ExpirePlotHoldsAction $action): void
    {
        $action->handle();
    }
}
