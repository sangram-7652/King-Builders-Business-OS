<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Leads\MarkMissedFollowUps;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Hourly M13.1 sweep — mark overdue PENDING follow-ups as MISSED. Idempotent
 * and {@see ShouldBeUnique}, so overlapping schedule ticks are harmless.
 */
class MarkMissedFollowUpsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 600;

    public function handle(MarkMissedFollowUps $action): void
    {
        $missed = $action->handle();

        if ($missed > 0) {
            Log::info('lead.follow_ups_missed_swept', ['count' => $missed]);
        }
    }
}
