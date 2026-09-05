<?php

declare(strict_types=1);

namespace App\Http\Controllers\Communication;

use App\Communication\WebhookVerifier;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Http\Controllers\Controller;
use App\Models\Communication;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Provider delivery webhook (M16.4 / F-M16-1) — the ONLY path that can move a
 * communication SENT → DELIVERED (or SENT → FAILED on a bounce). Delivery is
 * never inferred anywhere else.
 *
 * Unauthenticated (providers cannot present a session) but HMAC-signed per
 * channel — an unsigned / bad-signature request is 403. Every accepted event
 * is persisted with a unique `(channel, event_id)` so a re-sent event is a
 * no-op. Resolution is scoped by channel, so an event on one channel can never
 * touch another channel's message.
 *
 * Normalised payload: `{ "event_id": "...", "provider_message_id": "...",
 * "event": "delivered" | "failed" | "bounced" }`. Provider-specific payload
 * shapes are adapted upstream (not built here).
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $channel, WebhookVerifier $verifier): JsonResponse
    {
        $channelEnum = CommunicationChannel::tryFrom($channel);

        if ($channelEnum === null) {
            return response()->json(['message' => 'Unknown channel.'], 404);
        }

        $raw = $request->getContent();

        if (! $verifier->verify($channelEnum, $raw, $request->header(WebhookVerifier::SIGNATURE_HEADER))) {
            Log::warning('communication.webhook_rejected', ['channel' => $channel, 'reason' => 'bad signature']);

            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $data = json_decode($raw, true);

        if (! is_array($data) || ! isset($data['event_id'], $data['event'])) {
            return response()->json(['message' => 'Malformed payload.'], 422);
        }

        $eventId = (string) $data['event_id'];
        $eventType = (string) $data['event'];
        $providerMessageId = isset($data['provider_message_id']) ? (string) $data['provider_message_id'] : null;

        // Idempotency: claim the event id. A duplicate insert (unique index) →
        // we have already processed it → 200 no-op.
        try {
            $eventRowId = DB::table('communication_webhook_events')->insertGetId([
                'channel' => $channelEnum->value,
                'event_id' => $eventId,
                'provider_message_id' => $providerMessageId,
                'event_type' => $eventType,
                'payload' => $raw,
                'received_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'Already processed.'], 200);
        }

        $communication = $providerMessageId === null ? null : Communication::query()
            ->where('channel', $channelEnum->value)
            ->where('provider_message_id', $providerMessageId)
            ->first();

        if ($communication === null) {
            // Accept + record the event, but there is nothing to update.
            return response()->json(['message' => 'Accepted (no matching message).'], 202);
        }

        DB::table('communication_webhook_events')->where('id', $eventRowId)
            ->update(['communication_id' => $communication->id]);

        $this->apply($communication, $eventType);

        return response()->json(['message' => 'Accepted.'], 200);
    }

    /** Only a SENT message may become DELIVERED / FAILED from a provider report. */
    private function apply(Communication $communication, string $eventType): void
    {
        $delivered = ['delivered', 'read', 'opened'];
        $failed = ['failed', 'bounced', 'undelivered', 'rejected'];

        if (in_array($eventType, $delivered, true)
            && $communication->status === CommunicationStatus::Sent) {
            $communication->markDelivered();
            Log::info('communication.delivered', ['id' => $communication->id, 'event' => $eventType]);

            return;
        }

        if (in_array($eventType, $failed, true)
            && $communication->status === CommunicationStatus::Sent) {
            $communication->markFailed("provider reported: {$eventType}");
            Log::info('communication.provider_failure', ['id' => $communication->id, 'event' => $eventType]);
        }
    }
}
