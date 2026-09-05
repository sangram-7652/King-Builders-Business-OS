<?php

declare(strict_types=1);

use App\Communication\CommunicationManager;
use App\Communication\ProviderResult;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Exceptions\DomainException;
use App\Jobs\SendCommunicationJob;
use App\Models\Communication;
use App\Services\Communication\Communicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('persists a queued communication and dispatches the send job', function () {
    Queue::fake();

    $comm = app(Communicator::class)->send(commRequest());

    expect($comm->status)->toBe(CommunicationStatus::Queued)
        ->and($comm->uuid)->not->toBeNull()
        ->and($comm->event_key)->toBe('payment.received')
        ->and($comm->context)->toBe(['receipt_number' => 'RCPT-000001']);

    Queue::assertPushed(SendCommunicationJob::class, fn ($job) => $job->communicationId === $comm->id);
});

it('sends through the channel provider and records SENT + a provider message id', function () {
    $fake = app(CommunicationManager::class)->fake();

    $comm = app(Communicator::class)->send(commRequest());

    $fake->assertSentCount(1);
    $fresh = $comm->fresh();
    expect($fresh->status)->toBe(CommunicationStatus::Sent)
        ->and($fresh->provider)->toBe('fake')
        ->and($fresh->provider_message_id)->not->toBeNull()
        ->and($fresh->sent_at)->not->toBeNull()
        ->and($fresh->attempts)->toBe(1)
        ->and($fresh->delivered_at)->toBeNull(); // the fake reports acceptance, not delivery-via-webhook
});

it('never marks DELIVERED from a provider that only confirms submission', function () {
    $fake = app(CommunicationManager::class)->fake();
    $fake->willReturn(ProviderResult::accepted('x-1', confirmsDelivery: false));

    $comm = app(Communicator::class)->send(commRequest())->fresh();

    expect($comm->status)->toBe(CommunicationStatus::Sent)
        ->and($comm->status)->not->toBe(CommunicationStatus::Delivered);
});

it('is idempotent — the same idempotency key never produces two messages', function () {
    Queue::fake();

    $a = app(Communicator::class)->send(commRequest(['idempotencyKey' => 'payment-received:99']));
    $b = app(Communicator::class)->send(commRequest(['idempotencyKey' => 'payment-received:99', 'body' => 'different body']));

    expect($b->id)->toBe($a->id)
        ->and(Communication::count())->toBe(1)
        ->and($a->fresh()->body)->toBe('Your receipt RCPT-000001 is ready.');

    Queue::assertPushed(SendCommunicationJob::class, 1);
});

it('stores no subject for a non-email channel', function () {
    $fake = app(CommunicationManager::class)->fake();

    $comm = app(Communicator::class)->send(commRequest([
        'channel' => CommunicationChannel::WhatsApp, 'to' => '+919812345678', 'subject' => 'ignored',
    ]))->fresh();

    expect($comm->subject)->toBeNull()
        ->and($comm->channel)->toBe(CommunicationChannel::WhatsApp);
});

it('rejects a request with an empty recipient or an invalid email', function () {
    expect(fn () => app(Communicator::class)->send(commRequest(['to' => '   '])))
        ->toThrow(DomainException::class, 'recipient');

    expect(fn () => app(Communicator::class)->send(commRequest(['to' => 'not-an-email'])))
        ->toThrow(DomainException::class, 'valid address');
});

it('freezes the body snapshot — the job re-sends exactly what was composed', function () {
    $fake = app(CommunicationManager::class)->fake();
    $comm = app(Communicator::class)->send(commRequest(['body' => 'Snapshot content 123']));

    expect($fake->sent[0]->body)->toBe('Snapshot content 123')
        ->and($comm->fresh()->body)->toBe('Snapshot content 123');
});
