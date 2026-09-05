<?php

declare(strict_types=1);

use App\Enums\CommunicationCategory;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Exceptions\DomainException;
use App\Models\Buyer;
use App\Models\Lead;
use App\Services\Communication\CommunicationRequest;
use App\Services\Communication\Communicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    Bus::fake(); // never dispatch the real delivery job in these tests
});

function marketingRequest(array $o = [])
{
    return commRequest(array_merge([
        'category' => CommunicationCategory::Marketing,
        'channel' => CommunicationChannel::Email,
        'eventKey' => 'promo.newsletter',
        'body' => 'New plots released in Green Meadows — book a site visit.',
    ], $o));
}

/*
| F-M16-3 — MARKETING messages are gated by recipient consent; transactional
| messages are not.
*/

it('blocks a marketing message with no known recipient', function () {
    expect(fn () => app(Communicator::class)->send(marketingRequest()))
        ->toThrow(DomainException::class, 'known recipient');
});

it('blocks a marketing message to a buyer who has not consented', function () {
    $buyer = Buyer::factory()->create(['email' => 'no-consent@example.com']);

    $request = new CommunicationRequest(
        channel: CommunicationChannel::Email,
        category: CommunicationCategory::Marketing,
        to: $buyer->email,
        body: 'Promo',
        subject: 'News',
        eventKey: 'promo.newsletter',
        buyer: $buyer,
    );

    expect(fn () => app(Communicator::class)->send($request))
        ->toThrow(DomainException::class, 'not consented');
});

it('blocks a marketing message to a buyer who opted out even after consenting', function () {
    $buyer = Buyer::factory()->create(['email' => 'opted-out@example.com']);
    $buyer->grantMarketingConsent();
    $buyer->optOutOfMarketing();

    $request = new CommunicationRequest(
        channel: CommunicationChannel::Email,
        category: CommunicationCategory::Marketing,
        to: $buyer->email,
        body: 'Promo',
        eventKey: 'promo.newsletter',
        buyer: $buyer,
    );

    expect(fn () => app(Communicator::class)->send($request))
        ->toThrow(DomainException::class, 'opted out');
});

it('allows a marketing message to a consented buyer', function () {
    $buyer = Buyer::factory()->create(['email' => 'yes@example.com']);
    $buyer->grantMarketingConsent();

    $request = new CommunicationRequest(
        channel: CommunicationChannel::Email,
        category: CommunicationCategory::Marketing,
        to: $buyer->email,
        body: 'Promo',
        eventKey: 'promo.newsletter',
        buyer: $buyer,
    );

    $comm = app(Communicator::class)->send($request);

    expect($comm->category)->toBe(CommunicationCategory::Marketing)
        ->and($comm->status)->toBe(CommunicationStatus::Queued);
});

it('allows a marketing message to a consented lead', function () {
    $lead = Lead::factory()->create(['email' => 'lead@example.com']);
    $lead->grantMarketingConsent();

    $request = new CommunicationRequest(
        channel: CommunicationChannel::Email,
        category: CommunicationCategory::Marketing,
        to: $lead->email,
        body: 'Promo',
        eventKey: 'promo.newsletter',
        lead: $lead,
    );

    expect(app(Communicator::class)->send($request)->exists)->toBeTrue();
});

it('never gates a transactional message', function () {
    $buyer = Buyer::factory()->create(['email' => 'txn@example.com']); // no consent at all

    $request = new CommunicationRequest(
        channel: CommunicationChannel::Email,
        category: CommunicationCategory::Transactional,
        to: $buyer->email,
        body: 'Your receipt RCPT-000001 is ready.',
        eventKey: 'payment.received',
        buyer: $buyer,
    );

    expect(app(Communicator::class)->send($request)->status)->toBe(CommunicationStatus::Queued);
});
