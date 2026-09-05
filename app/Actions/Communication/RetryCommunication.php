<?php

declare(strict_types=1);

namespace App\Actions\Communication;

use App\Enums\CommunicationStatus;
use App\Exceptions\DomainException;
use App\Jobs\SendCommunicationJob;
use App\Models\Communication;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Manually re-queues a FAILED communication (M16). Idempotent — a message
 * already back in the pipeline (or delivered) is left alone. The rendered body
 * snapshot is NOT re-rendered: the customer gets exactly what was composed.
 */
class RetryCommunication
{
    use RunsInTransaction;

    public function handle(Communication $communication, User $actor): Communication
    {
        return $this->transaction(function () use ($communication, $actor): Communication {
            /** @var Communication $locked */
            $locked = Communication::query()->whereKey($communication->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [CommunicationStatus::Queued, CommunicationStatus::Sending, CommunicationStatus::Sent, CommunicationStatus::Delivered], true)) {
                return $locked; // already in flight or done
            }

            if ($locked->status !== CommunicationStatus::Failed) {
                throw new DomainException("A {$locked->status->label()} communication cannot be retried.");
            }

            $locked->forceFill([
                'status' => CommunicationStatus::Queued,
                'queued_at' => now(),
                'failed_at' => null,
                'error' => null,
            ])->save();

            SendCommunicationJob::dispatch($locked->id)
                ->onQueue((string) config('communication.queue', 'default'));

            Log::info('communication.retried', ['id' => $locked->id, 'by' => $actor->id]);

            return $locked;
        });
    }
}
