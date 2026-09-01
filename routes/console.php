<?php

declare(strict_types=1);

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
