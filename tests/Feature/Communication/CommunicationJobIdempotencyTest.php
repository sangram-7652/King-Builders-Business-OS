<?php

declare(strict_types=1);

use App\Communication\CommunicationManager;
use App\Enums\CommunicationStatus;
use App\Jobs\SendCommunicationJob;
use App\Models\Communication;
use App\Services\Communication\Communicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRbac());

/**
 * F-M16-2 — one logical communication cannot be delivered twice even if the
 * queue's unique lock expires and a duplicate job instance runs. The DB claim
 * (`Communication::claimForSending`) is the persistent guard.
 */
it('delivers exactly once when two job instances run for the same communication', function () {
    Queue::fake(); // do not auto-run the job on send()
    $fake = app(CommunicationManager::class)->fake();

    $comm = app(Communicator::class)->send(commRequest());

    // Two independent job instances (a lock-expiry duplicate).
    (new SendCommunicationJob($comm->id))->handle(app(CommunicationManager::class));
    (new SendCommunicationJob($comm->id))->handle(app(CommunicationManager::class));

    $fake->assertSentCount(1);
    expect($comm->fresh()->status)->toBe(CommunicationStatus::Sent);
});

it('a second worker skips a communication that is freshly SENDING', function () {
    $comm = Communication::factory()->create([
        'status' => CommunicationStatus::Sending->value,
        'sending_at' => now(), // fresh lease
    ]);

    expect($comm->claimForSending())->toBeFalse();
});

it('a stale SENDING (dead worker) can be re-claimed after the lease expires', function () {
    $comm = Communication::factory()->create([
        'status' => CommunicationStatus::Sending->value,
        'sending_at' => now()->subHour(), // lease long expired
    ]);

    expect($comm->claimForSending(leaseSeconds: 300))->toBeTrue()
        ->and($comm->fresh()->status)->toBe(CommunicationStatus::Sending);
});

it('a FAILED communication can be re-claimed and re-sent', function () {
    Queue::fake();
    $fake = app(CommunicationManager::class)->fake();

    $comm = app(Communicator::class)->send(commRequest());
    $comm->forceFill(['status' => CommunicationStatus::Failed->value])->save();

    (new SendCommunicationJob($comm->id))->handle(app(CommunicationManager::class));

    $fake->assertSentCount(1);
    expect($comm->fresh()->status)->toBe(CommunicationStatus::Sent);
});

it('each claim increments the attempt counter exactly once', function () {
    $comm = Communication::factory()->create([
        'status' => CommunicationStatus::Queued->value, 'attempts' => 0,
    ]);

    $comm->claimForSending();
    expect($comm->fresh()->attempts)->toBe(1);

    // Fresh SENDING now — a second claim does nothing.
    $comm->claimForSending();
    expect($comm->fresh()->attempts)->toBe(1);
});
