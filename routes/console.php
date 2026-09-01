<?php

declare(strict_types=1);

use App\Jobs\ExpireDocumentsJob;
use App\Jobs\ExpirePlotHoldsJob;
use App\Jobs\RefreshCollectionQueueJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| ---------------------------------------------------------------------------
| Scheduled tasks
| ---------------------------------------------------------------------------
| Runs inside the dedicated `scheduler` container (php artisan schedule:work).
| M0 keeps one lightweight task so the scheduler is observably alive; real
| jobs are registered by their modules.
*/
Schedule::command('queue:prune-batches --hours=48')->daily();

Schedule::call(fn () => logger()->info('scheduler heartbeat'))
    ->everyFifteenMinutes()
    ->name('scheduler-heartbeat')
    ->withoutOverlapping();

// M4: release plots whose hold has lapsed. Queued (ExpirePlotHoldsJob is
// ShouldBeUnique) and idempotent — safe to run every few minutes.
Schedule::job(new ExpirePlotHoldsJob)
    ->everyFiveMinutes()
    ->name('expire-plot-holds')
    ->withoutOverlapping();

// M8: nightly collection sweep — open cases for newly-overdue bookings, break
// stale promises, re-prioritise, regenerate reminders. Idempotent + unique.
Schedule::job(new RefreshCollectionQueueJob)
    ->dailyAt('01:30')
    ->name('refresh-collection-queue')
    ->withoutOverlapping();

// M9: mark documents past their expiry as EXPIRED. Idempotent + unique.
Schedule::job(new ExpireDocumentsJob)
    ->dailyAt('02:00')
    ->name('expire-documents')
    ->withoutOverlapping();
