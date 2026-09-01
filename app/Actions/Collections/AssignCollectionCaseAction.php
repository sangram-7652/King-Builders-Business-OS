<?php

declare(strict_types=1);

namespace App\Actions\Collections;

use App\Actions\Collections\Concerns\SyncsCollectionCase;
use App\Enums\CollectionActivityType;
use App\Enums\CollectionCaseStatus;
use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use App\Models\CollectionCase;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;
use Illuminate\Support\Facades\Log;

/**
 * Assigns (or unassigns) a collection case to a user (M8). `collections.assign`
 * only. An OPEN case moves to IN_PROGRESS on first assignment.
 */
class AssignCollectionCaseAction
{
    use RunsInTransaction;
    use SyncsCollectionCase;

    public function handle(CollectionCase $case, ?User $assignee, User $actor): CollectionCase
    {
        if (! $actor->can('collections.assign')) {
            throw new DomainException('You are not authorised to assign collection cases.');
        }

        if ($assignee !== null && $assignee->status !== UserStatus::Active) {
            throw new DomainException('A collection case cannot be assigned to an inactive user.');
        }

        return $this->transaction(function () use ($case, $assignee, $actor): CollectionCase {
            /** @var CollectionCase $locked */
            $locked = CollectionCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->assigned_to === $assignee?->id) {
                return $locked;
            }

            $locked->forceFill(['assigned_to' => $assignee?->id])->save();

            if ($assignee !== null && $locked->status === CollectionCaseStatus::Open) {
                $locked->forceFill(['status' => CollectionCaseStatus::InProgress])->save();
            }

            $locked->recordActivity(
                CollectionActivityType::CaseAssigned,
                $assignee !== null ? "Assigned to {$assignee->name}." : 'Unassigned.',
                ['assigned_to' => $assignee?->id],
                $actor,
            );

            Log::info('collection_case.assigned', [
                'collection_case_id' => $locked->id,
                'booking_id' => $locked->booking_id,
                'assigned_to' => $assignee?->id,
                'by' => $actor->id,
            ]);

            return $this->syncCase($locked);
        });
    }
}
