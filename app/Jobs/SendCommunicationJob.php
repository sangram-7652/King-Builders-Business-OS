<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Communication\CommunicationManager;
use App\Enums\CommunicationStatus;
use App\Models\Communication;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Delivers one {@see Communication} through its channel provider (M16).
 *
 *  - the ONLY place an external provider is called
 *  - {@see ShouldBeUnique} on the communication id so a duplicate dispatch is a no-op
 *  - retried with backoff on a transient failure; a permanent (4xx-class)
 *    provider rejection stops immediately
 *  - a delivered / cancelled record is never touched
 *
 * A message reaches SENT when the provider accepts it. DELIVERED is set only by
 * a real provider delivery webhook (M16.4) — never inferred here.
 */
class SendCommunicationJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 300, 900];

    // Secondary de-dupe only (the DB claim in handle() is the real guard).
    // Wide enough that a job sitting in a backed-up queue is not re-dispatched
    // by RetryCommunication while it still waits to be processed.
    public int $uniqueFor = 86400;

    public function __construct(public readonly int $communicationId) {}

    public function uniqueId(): string
    {
        return 'communication:'.$this->communicationId;
    }

    public function handle(CommunicationManager $manager): void
    {
        $communication = Communication::find($this->communicationId);

        if ($communication === null
            || $communication->status === CommunicationStatus::Cancelled
            || $communication->status === CommunicationStatus::Delivered
            || $communication->status === CommunicationStatus::Sent) {
            return;
        }

        // F-M16-2 — one worker only. If the atomic claim fails, another worker
        // already owns this send (or it has moved on) — do nothing, do NOT
        // throw (a throw would trigger a pointless retry).
        if (! $communication->claimForSending()) {
            Log::info('communication.send_skipped', [
                'id' => $communication->id,
                'status' => $communication->status->value,
                'reason' => 'not claimed — another worker owns it',
            ]);

            return;
        }

        $provider = $manager->for($communication->channel);

        try {
            $result = $provider->send($communication->toOutboundMessage());
        } catch (Throwable $e) {
            // A thrown exception is a transient transport error — let the queue retry.
            $communication->markFailed('transport error: '.$e->getMessage());
            throw $e;
        }

        if ($result->accepted) {
            $communication->markSent($provider->name(), $result->providerMessageId);

            Log::info('communication.sent', [
                'id' => $communication->id,
                'provider' => $provider->name(),
                'provider_message_id' => $result->providerMessageId,
                'confirms_delivery' => $result->confirmsDelivery,
            ]);

            return;
        }

        $communication->markFailed($result->error ?? 'provider rejected the message');

        if ($result->permanentFailure) {
            Log::warning('communication.permanent_failure', ['id' => $communication->id, 'error' => $result->error]);

            return; // do not retry
        }

        // Transient rejection — throw so the queue applies backoff and retries.
        throw new \RuntimeException('communication temporary failure: '.($result->error ?? 'unknown'));
    }

    public function failed(Throwable $exception): void
    {
        Communication::query()
            ->whereKey($this->communicationId)
            ->whereNotIn('status', [CommunicationStatus::Delivered->value, CommunicationStatus::Cancelled->value])
            ->update([
                'status' => CommunicationStatus::Failed->value,
                'failed_at' => now(),
                'error' => Str::limit('exhausted retries: '.$exception->getMessage(), 250, ''),
            ]);
    }
}
