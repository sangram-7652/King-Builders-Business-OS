<?php

declare(strict_types=1);

use App\Jobs\HealthPingJob;
use Illuminate\Support\Facades\Queue;

it('pushes the health ping job onto the queue', function () {
    Queue::fake();

    HealthPingJob::dispatch('test');

    Queue::assertPushed(HealthPingJob::class, fn (HealthPingJob $job) => $job->source === 'test');
});

it('runs the health ping job without error', function () {
    (new HealthPingJob('sync'))->handle();
})->throwsNoExceptions();
