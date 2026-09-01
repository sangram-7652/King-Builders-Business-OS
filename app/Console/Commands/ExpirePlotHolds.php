<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Plots\ExpirePlotHoldsAction;
use Illuminate\Console\Command;

class ExpirePlotHolds extends Command
{
    protected $signature = 'plots:expire-holds';

    protected $description = 'Return plots whose hold has lapsed back to AVAILABLE (idempotent).';

    public function handle(ExpirePlotHoldsAction $action): int
    {
        $count = $action->handle();

        $this->info($count === 0 ? 'No lapsed holds.' : "Released {$count} lapsed hold(s).");

        return self::SUCCESS;
    }
}
