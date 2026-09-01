<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Collections\GenerateCollectionRemindersAction;
use App\Actions\Collections\RefreshCollectionQueueAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Nightly M8 sweep: open cases for newly-overdue bookings, re-evaluate promises
 * (mark BROKEN past their date), re-sync priority / resolution, and regenerate
 * internal reminders. Idempotent and `ShouldBeUnique`.
 */
class RefreshCollectionQueueJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 1800;

    public function handle(RefreshCollectionQueueAction $refresh, GenerateCollectionRemindersAction $reminders): void
    {
        $result = $refresh->handle();
        $created = $reminders->handle();

        Log::info('collection.queue_refreshed', $result + ['reminders_created' => $created]);
    }
}
