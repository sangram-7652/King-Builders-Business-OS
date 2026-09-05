<?php

declare(strict_types=1);

use App\Communication\WebhookVerifier;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Models\Communication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRbac();
    config()->set('communication.webhooks.email.secret', 'email-webhook-secret');
    config()->set('communication.webhooks.sms.secret', 'sms-webhook-secret');
});

function sentEmail(string $providerMessageId = 'pm-123'): Communication
{
    return Communication::factory()->create([
        'channel' => CommunicationChannel::Email->value,
        'status' => CommunicationStatus::Sent->value,
        'provider' => 'mail:log',
        'provider_message_id' => $providerMessageId,
        'sent_at' => now(),
    ]);
}

function post_webhook(string $channel, array $payload, ?string $signatureOverride = null)
{
    $raw = json_encode($payload);
    $secret = config("communication.webhooks.{$channel}.secret");
    $sig = $signatureOverride ?? ($secret ? hash_hmac('sha256', $raw, $secret) : null);

    return test()->call(
        'POST',
        "/webhooks/communication/{$channel}",
        [], [], [],
        array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_'.str_replace('-', '_', strtoupper(WebhookVerifier::SIGNATURE_HEADER)) => $sig,
        ]),
        $raw,
    );
}

it('marks a SENT message DELIVERED on a valid signed delivered event', function () {
    $c = sentEmail();

    post_webhook('email', ['event_id' => 'e1', 'provider_message_id' => 'pm-123', 'event' => 'delivered'])
        ->assertOk();

    expect($c->fresh()->status)->toBe(CommunicationStatus::Delivered)
        ->and($c->fresh()->delivered_at)->not->toBeNull();
});

it('rejects an invalid signature with 403 and changes nothing', function () {
    $c = sentEmail();

    post_webhook('email', ['event_id' => 'e1', 'provider_message_id' => 'pm-123', 'event' => 'delivered'], 'deadbeef')
        ->assertForbidden();

    expect($c->fresh()->status)->toBe(CommunicationStatus::Sent);
});

it('rejects a missing signature with 403', function () {
    sentEmail();

    test()->call('POST', '/webhooks/communication/email', [], [], [], ['CONTENT_TYPE' => 'application/json'],
        json_encode(['event_id' => 'e1', 'provider_message_id' => 'pm-123', 'event' => 'delivered']))
        ->assertForbidden();
});

it('rejects a channel with no configured secret', function () {
    config()->set('communication.webhooks.whatsapp.secret', null);
    Communication::factory()->create([
        'channel' => CommunicationChannel::WhatsApp->value,
        'status' => CommunicationStatus::Sent->value, 'provider_message_id' => 'wa-1',
    ]);

    post_webhook('whatsapp', ['event_id' => 'e1', 'provider_message_id' => 'wa-1', 'event' => 'delivered'])
        ->assertForbidden();
});

it('is idempotent — a re-sent event applies the state change only once', function () {
    $c = sentEmail();

    post_webhook('email', ['event_id' => 'dup', 'provider_message_id' => 'pm-123', 'event' => 'delivered'])->assertOk();
    post_webhook('email', ['event_id' => 'dup', 'provider_message_id' => 'pm-123', 'event' => 'delivered'])->assertOk();

    expect($c->fresh()->status)->toBe(CommunicationStatus::Delivered)
        ->and(DB::table('communication_webhook_events')->where('event_id', 'dup')->count())->toBe(1);
});

it('accepts but does not act on an event for an unknown provider message id', function () {
    post_webhook('email', ['event_id' => 'e9', 'provider_message_id' => 'nope', 'event' => 'delivered'])
        ->assertStatus(202);
});

it('never crosses channels — an sms event cannot touch an email message', function () {
    $email = sentEmail('shared-id');

    post_webhook('sms', ['event_id' => 's1', 'provider_message_id' => 'shared-id', 'event' => 'delivered'])
        ->assertStatus(202); // no sms message with that id

    expect($email->fresh()->status)->toBe(CommunicationStatus::Sent);
});

it('never marks a non-SENT message DELIVERED', function () {
    $c = Communication::factory()->create([
        'channel' => CommunicationChannel::Email->value,
        'status' => CommunicationStatus::Failed->value,
        'provider_message_id' => 'pm-fail',
    ]);

    post_webhook('email', ['event_id' => 'e1', 'provider_message_id' => 'pm-fail', 'event' => 'delivered'])->assertOk();

    expect($c->fresh()->status)->toBe(CommunicationStatus::Failed);
});

it('marks a SENT message FAILED on a bounce event', function () {
    $c = sentEmail('pm-bounce');

    post_webhook('email', ['event_id' => 'b1', 'provider_message_id' => 'pm-bounce', 'event' => 'bounced'])->assertOk();

    expect($c->fresh()->status)->toBe(CommunicationStatus::Failed)
        ->and($c->fresh()->error)->toContain('bounced');
});

it('422s a malformed payload (after signature passes)', function () {
    post_webhook('email', ['not' => 'valid'])->assertStatus(422);
});
