<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Documents\ExpireDocumentsAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Daily M9 sweep: marks documents past their `expires_at` as EXPIRED.
 * Idempotent and `ShouldBeUnique`.
 */
class ExpireDocumentsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 1800;

    public function handle(ExpireDocumentsAction $action): void
    {
        $count = $action->handle();
        Log::info('documents.expired_sweep', ['expired' => $count]);
    }
}
