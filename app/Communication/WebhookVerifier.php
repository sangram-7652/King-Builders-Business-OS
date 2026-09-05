<?php

declare(strict_types=1);

namespace App\Communication;

use App\Enums\CommunicationChannel;

/**
 * Verifies a provider delivery webhook (M16.4 / F-M16-1).
 *
 * Each channel has its own shared secret in `config('communication.webhooks')`
 * (env only, never committed). A webhook is authentic when the request carries
 * `HMAC-SHA256(raw body, secret)` (hex) in the signature header and it matches
 * with a constant-time compare. A channel with no configured secret rejects
 * every webhook — delivery tracking is opt-in per channel.
 */
final class WebhookVerifier
{
    public const SIGNATURE_HEADER = 'X-Webhook-Signature';

    public function secretFor(CommunicationChannel $channel): ?string
    {
        $secret = config("communication.webhooks.{$channel->value}.secret");

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    public function verify(CommunicationChannel $channel, string $rawBody, ?string $signature): bool
    {
        $secret = $this->secretFor($channel);

        if ($secret === null || $signature === null || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, trim($signature));
    }

    /** Convenience for building a valid signature (tests, provider config docs). */
    public function sign(CommunicationChannel $channel, string $rawBody): ?string
    {
        $secret = $this->secretFor($channel);

        return $secret === null ? null : hash_hmac('sha256', $rawBody, $secret);
    }
}
