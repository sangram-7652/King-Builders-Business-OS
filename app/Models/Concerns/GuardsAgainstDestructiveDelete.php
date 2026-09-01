<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Business entities that later milestones will reference (a project referenced
 * by plots/bookings, a block referenced by plots) must never be destructively
 * deleted while those references exist.
 *
 * M3 has no downstream tables yet, so `businessDependents()` returns an empty
 * map. Each consuming milestone adds its relation here, e.g.:
 *
 *   protected function businessDependents(): array
 *   {
 *       return ['plots' => $this->plots(), 'bookings' => $this->bookings()];
 *   }
 *
 * The delete Actions call `hasBusinessDependents()` before removing anything.
 */
trait GuardsAgainstDestructiveDelete
{
    /**
     * @return array<string, \Illuminate\Database\Eloquent\Relations\Relation<*, *, *>>
     */
    protected function businessDependents(): array
    {
        return [];
    }

    public function hasBusinessDependents(): bool
    {
        foreach ($this->businessDependents() as $relation) {
            if ($relation->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Names of the dependent relations that currently hold records.
     *
     * @return list<string>
     */
    public function blockingDependents(): array
    {
        $blocking = [];

        foreach ($this->businessDependents() as $name => $relation) {
            if ($relation->exists()) {
                $blocking[] = $name;
            }
        }

        return $blocking;
    }
}
