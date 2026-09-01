<?php

declare(strict_types=1);

namespace App\Models\Masters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Base class for every Master Data table (M2).
 *
 * Shared behaviour:
 *  - soft deletes: a master referenced by historical business data must remain
 *    resolvable even after being "removed" (see `isReferenced()` / DeleteMaster).
 *  - `is_active` flag: the primary lifecycle control is activate / deactivate.
 *  - `sort_order` + display column drive listing order.
 *  - `is_system` rows (seeded reference data) are protected from deletion.
 *
 * Every master table has: is_active (bool), sort_order (uint), timestamps,
 * soft deletes.
 *
 * @property bool $is_active
 * @property int $sort_order
 */
abstract class MasterModel extends Model
{
    use SoftDeletes;

    /**
     * Columns matched by the generic search box.
     *
     * @var list<string>
     */
    protected array $searchable = ['name'];

    /** Human-facing label column for this master. */
    protected string $displayColumn = 'name';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_active' => 'boolean',
        ]);
    }

    public function displayName(): string
    {
        return (string) $this->getAttribute($this->displayColumn);
    }

    public function displayColumnName(): string
    {
        return $this->displayColumn;
    }

    // --- Scopes ---------------------------------------------------------

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param  Builder<static>  $query */
    public function scopeInactive(Builder $query): void
    {
        $query->where('is_active', false);
    }

    /** @param  Builder<static>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '' || $this->searchable === []) {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            foreach ($this->searchable as $column) {
                $query->orWhere($column, 'like', "%{$term}%");
            }
        });
    }

    /** @param  Builder<static>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy($this->displayColumn);
    }

    // --- Lifecycle helpers ------------------------------------------------

    /** Reference rows seeded by the system; protected from deletion. Overridden by HasSystemFlag. */
    public function isSystem(): bool
    {
        return false;
    }

    /**
     * Relations that, if non-empty, mean this row is in use by business data and
     * must not be hard-deleted. Empty until the consuming modules land.
     *
     * @return list<string>
     */
    public function referencingRelations(): array
    {
        return [];
    }

    public function isReferenced(): bool
    {
        foreach ($this->referencingRelations() as $relation) {
            if ($this->{$relation}()->exists()) {
                return true;
            }
        }

        return false;
    }
}
