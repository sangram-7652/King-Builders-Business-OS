<?php

declare(strict_types=1);

namespace App\Actions\Collections;

use App\Actions\Collections\Concerns\SyncsCollectionCase;
use App\Enums\CollectionActivityType;
use App\Enums\CollectionCaseStatus;
use App\Enums\CollectionFollowUpOutcome;
use App\Exceptions\DomainException;
use App\Models\CollectionFollowUp;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;

/**
 * Records the outcome of a follow-up (M8). Idempotent — completing an already
 * completed follow-up returns it unchanged. Optionally schedules the next
 * follow-up as a fresh row.
 */
class CompleteCollectionFollowUpAction
{
    use RunsInTransaction;
    use SyncsCollectionCase;

    public function __construct(private readonly ScheduleCollectionFollowUpAction $scheduler) {}

    /**
     * @param  array{outcome: string, notes?: string|null, next_follow_up_at?: string|null}  $data
     */
    public function handle(CollectionFollowUp $followUp, array $data, User $actor): CollectionFollowUp
    {
        $outcome = CollectionFollowUpOutcome::tryFrom((string) ($data['outcome'] ?? ''));

        if ($outcome === null) {
            throw new DomainException('A follow-up outcome is required.');
        }

        return $this->transaction(function () use ($followUp, $data, $actor, $outcome): CollectionFollowUp {
            /** @var CollectionFollowUp $locked */
            $locked = CollectionFollowUp::query()->whereKey($followUp->getKey())->lockForUpdate()->firstOrFail();
            $locked->load('collectionCase.booking');

            if ($locked->isCompleted()) {
                return $locked;
            }

            $locked->forceFill([
                'outcome' => $outcome,
                'notes' => $data['notes'] ?? $locked->notes,
                'completed_at' => now(),
                'next_follow_up_at' => $data['next_follow_up_at'] ?? null,
            ])->save();

            $case = $locked->collectionCase;
            $case->forceFill(['last_follow_up_at' => now()])->save();

            if ($case->status === CollectionCaseStatus::Open) {
                $case->forceFill(['status' => CollectionCaseStatus::InProgress])->save();
            }

            $case->recordActivity(
                CollectionActivityType::FollowUpCompleted,
                "Follow-up completed: {$outcome->label()}.",
                ['follow_up_id' => $locked->id, 'outcome' => $outcome->value],
                $actor,
            );

            if (! empty($data['next_follow_up_at'])) {
                $this->scheduler->handle($case, [
                    'follow_up_at' => $data['next_follow_up_at'],
                    'installment_id' => $locked->installment_id,
                    'assigned_to' => $locked->assigned_to,
                ], $actor);
            }

            $this->syncCase($case);

            return $locked;
        });
    }
}
