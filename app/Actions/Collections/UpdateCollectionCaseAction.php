<?php

declare(strict_types=1);

namespace App\Actions\Collections;

use App\Actions\Collections\Concerns\SyncsCollectionCase;
use App\Enums\CollectionActivityType;
use App\Enums\CollectionCaseStatus;
use App\Exceptions\DomainException;
use App\Models\CollectionCase;
use App\Models\User;
use App\Support\Concerns\RunsInTransaction;

/**
 * Updates the workflow fields of a collection case (M8): status + notes.
 * RESOLVED is never a manual choice — it is set automatically when M7
 * outstanding reaches zero.
 */
class UpdateCollectionCaseAction
{
    use RunsInTransaction;
    use SyncsCollectionCase;

    /**
     * @param  array{status?: string, notes?: string|null}  $data
     */
    public function handle(CollectionCase $case, array $data, User $actor): CollectionCase
    {
        return $this->transaction(function () use ($case, $data, $actor): CollectionCase {
            /** @var CollectionCase $locked */
            $locked = CollectionCase::query()->whereKey($case->getKey())->lockForUpdate()->firstOrFail();
            $locked->loadMissing('booking');

            if (array_key_exists('notes', $data)) {
                $locked->forceFill(['notes' => $data['notes']])->save();
            }

            if (! empty($data['status'])) {
                $target = CollectionCaseStatus::tryFrom($data['status']);

                if ($target === null || ! in_array($target, CollectionCaseStatus::assignableStatuses(), true)) {
                    throw new DomainException('That collection status cannot be set manually.');
                }

                if ($target !== $locked->status) {
                    $from = $locked->status;
                    $locked->forceFill(['status' => $target])->save();
                    $locked->recordActivity(
                        CollectionActivityType::CaseStatusChanged,
                        "Status {$from->label()} → {$target->label()}.",
                        ['from' => $from->value, 'to' => $target->value],
                        $actor,
                    );
                }
            }

            return $this->syncCase($locked);
        });
    }
}
