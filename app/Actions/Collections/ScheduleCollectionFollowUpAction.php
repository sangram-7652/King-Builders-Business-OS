<?php

declare(strict_types=1);

namespace App\Actions\Collections;

use App\Actions\Collections\Concerns\SyncsCollectionCase;
use App\Enums\CollectionActivityType;
use App\Models\CollectionCase;
use App\Models\CollectionFollowUp;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Database\QueryException;

/**
 * Schedules a collection follow-up on a case (M8). An `idempotency_key` makes a
 * retried request return the original row instead of creating a duplicate.
 */
class ScheduleCollectionFollowUpAction
{
    use RunsInTransaction;
    use SyncsCollectionCase;

    /**
     * @param  array{follow_up_at: string, installment_id?: int|null, assigned_to?: int|null, notes?: string|null, idempotency_key?: string|null}  $data
     */
    public function handle(CollectionCase $case, array $data, User $actor): CollectionFollowUp
    {
        $key = isset($data['idempotency_key']) && $data['idempotency_key'] !== ''
            ? (string) $data['idempotency_key'] : null;

        if ($key !== null && ($existing = CollectionFollowUp::query()->where('idempotency_key', $key)->first()) !== null) {
            return $existing;
        }

        try {
            return $this->transaction(function () use ($case, $data, $actor, $key): CollectionFollowUp {
                $followUp = $case->followUps()->create([
                    'booking_id' => $case->booking_id,
                    'installment_id' => $data['installment_id'] ?? null,
                    'assigned_to' => $data['assigned_to'] ?? $case->assigned_to,
                    'follow_up_at' => $data['follow_up_at'],
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $actor->id,
                    'idempotency_key' => $key,
                ]);

                $case->recordActivity(
                    CollectionActivityType::FollowUpScheduled,
                    'Follow-up scheduled for '.$followUp->follow_up_at->format('d M Y H:i').'.',
                    ['follow_up_id' => $followUp->id],
                    $actor,
                );

                $this->syncCase($case);

                return $followUp;
            });
        } catch (QueryException $e) {
            if ($key !== null && ($existing = CollectionFollowUp::query()->where('idempotency_key', $key)->first()) !== null) {
                return $existing;
            }

            throw $e;
        }
    }
}
