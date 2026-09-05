<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Exceptions\DomainException;
use App\Jobs\SendCommunicationJob;
use App\Models\Communication;
use Illuminate\Support\Facades\Log;

/**
 * The single entry point for sending a communication (M16). It PERSISTS a
 * {@see Communication} record and QUEUES the actual delivery — it never calls a
 * provider inline, so a domain transaction (booking, payment, CRM) is never
 * blocked or corrupted by an external failure.
 *
 * Idempotent: a request carrying an `idempotencyKey` that already produced a
 * live communication returns that record instead of creating a second.
 */
class Communicator
{
    public function send(CommunicationRequest $request): Communication
    {
        $to = trim($request->to);

        if ($to === '') {
            throw new DomainException('A communication needs a recipient address.');
        }
        if (trim($request->body) === '') {
            throw new DomainException('A communication needs a body.');
        }
        if ($request->channel === CommunicationChannel::Email && ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('The email recipient is not a valid address.');
        }

        // F-M16-3 — a MARKETING message may only go to a known party who has
        // active marketing consent (given and not opted out). Transactional
        // messages (receipts, registry dates, …) are never gated by this.
        if ($request->category->requiresConsent()) {
            $recipient = $request->buyer ?? $request->lead;

            if ($recipient === null) {
                throw new DomainException('A marketing message needs a known recipient (buyer or lead) with recorded consent.');
            }

            if (! $recipient->hasMarketingConsent()) {
                throw new DomainException('The recipient has not consented to marketing communications, or has opted out.');
            }
        }

        if ($request->idempotencyKey !== null) {
            $existing = Communication::query()
                ->where('idempotency_key', $request->idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $communication = Communication::create([
            'channel' => $request->channel,
            'category' => $request->category,
            'status' => CommunicationStatus::Pending,
            'to_address' => $to,
            'subject' => $request->channel->usesSubject() ? $request->subject : null,
            'body' => $request->body,
            'context' => $request->context,
            'event_key' => $request->eventKey,
            'subject_type' => $request->subjectModel?->getMorphClass(),
            'subject_id' => $request->subjectModel?->getKey(),
            'buyer_id' => $request->buyer?->getKey(),
            'lead_id' => $request->lead?->getKey(),
            'idempotency_key' => $request->idempotencyKey,
            'created_by' => $request->actor?->getKey(),
        ]);

        $communication->markQueued();

        SendCommunicationJob::dispatch($communication->id)
            ->onQueue((string) config('communication.queue', 'default'));

        Log::info('communication.queued', [
            'id' => $communication->id,
            'channel' => $communication->channel->value,
            'event' => $communication->event_key,
        ]);

        return $communication;
    }
}
