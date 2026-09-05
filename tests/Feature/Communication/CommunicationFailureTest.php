<?php

declare(strict_types=1);

use App\Actions\Communication\RetryCommunication;
use App\Communication\CommunicationManager;
use App\Communication\ProviderResult;
use App\Enums\CommunicationStatus;
use App\Exceptions\DomainException;
use App\Jobs\SendCommunicationJob;
use App\Models\Communication;
use App\Models\User;
use App\Services\Communication\Communicator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks a communication FAILED without retrying on a permanent provider rejection', function () {
    $fake = app(CommunicationManager::class)->fake();
    $fake->willReturn(ProviderResult::permanentFailure('recipient is on the block list'));

    $comm = app(Communicator::class)->send(commRequest())->fresh();

    expect($comm->status)->toBe(CommunicationStatus::Failed)
        ->and($comm->error)->toContain('block list')
        ->and($comm->attempts)->toBe(1);
    $fake->assertSentCount(1);
});

it('throws on a transient failure so the queue applies backoff', function () {
    $fake = app(CommunicationManager::class)->fake();
    $fake->willReturn(ProviderResult::temporaryFailure('provider 503'));

    $comm = Communication::factory()->status(CommunicationStatus::Queued)->create();

    expect(fn () => (new SendCommunicationJob($comm->id))->handle(app(CommunicationManager::class)))
        ->toThrow(RuntimeException::class);

    expect($comm->fresh()->status)->toBe(CommunicationStatus::Failed) // interim; the queue will re-run
        ->and($comm->fresh()->attempts)->toBe(1);
});

it('treats a thrown provider exception as transient and re-throws', function () {
    $fake = app(CommunicationManager::class)->fake();
    $fake->willThrow('connection reset');

    $comm = Communication::factory()->status(CommunicationStatus::Queued)->create();

    expect(fn () => (new SendCommunicationJob($comm->id))->handle(app(CommunicationManager::class)))
        ->toThrow(RuntimeException::class, 'connection reset');

    expect($comm->fresh()->status)->toBe(CommunicationStatus::Failed);
});

it('marks FAILED when the job exhausts its retries', function () {
    $comm = Communication::factory()->status(CommunicationStatus::Sending)->create();

    (new SendCommunicationJob($comm->id))->failed(new RuntimeException('gave up after 5 tries'));

    $fresh = $comm->fresh();
    expect($fresh->status)->toBe(CommunicationStatus::Failed)
        ->and($fresh->failed_at)->not->toBeNull()
        ->and($fresh->error)->toContain('exhausted retries');
});

it('does not overwrite a DELIVERED message from a late failure callback', function () {
    $comm = Communication::factory()->status(CommunicationStatus::Delivered)->create(['delivered_at' => now()]);

    (new SendCommunicationJob($comm->id))->failed(new RuntimeException('late'));

    expect($comm->fresh()->status)->toBe(CommunicationStatus::Delivered);
});

it('manually retries a FAILED communication idempotently', function () {
    $fake = app(CommunicationManager::class)->fake();
    $comm = Communication::factory()->failed()->create();
    $actor = User::factory()->create();

    app(RetryCommunication::class)->handle($comm, $actor);

    // sync queue → the retry job ran → the fake got it and it is SENT again
    expect($comm->fresh()->status)->toBe(CommunicationStatus::Sent)
        ->and($comm->fresh()->error)->toBeNull();
    $fake->assertSentCount(1);

    // retrying a non-failed message is a harmless no-op
    app(RetryCommunication::class)->handle($comm->fresh(), $actor);
    $fake->assertSentCount(1);
});

it('refuses to retry a cancelled communication', function () {
    $comm = Communication::factory()->status(CommunicationStatus::Cancelled)->create();

    expect(fn () => app(RetryCommunication::class)->handle($comm, User::factory()->create()))
        ->toThrow(DomainException::class);
});
