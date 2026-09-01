<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Models\Concerns\GuardsAgainstDestructiveDelete;
use App\Models\Masters\City;
use App\Models\Masters\State;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property ProjectStatus $status
 * @property bool $is_active
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use GuardsAgainstDestructiveDelete, HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'slug', 'description',
        'status', 'is_active',
        'address', 'state_id', 'city_id', 'pincode', 'latitude', 'longitude',
        'contact_name', 'contact_phone', 'contact_email',
        'logo_path', 'cover_image_path',
        'launch_date',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'is_active' => 'boolean',
            'state_id' => 'integer',
            'city_id' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'launch_date' => 'date',
        ];
    }

    // --- Relationships -------------------------------------------------

    /** @return BelongsTo<State, $this> */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /** @return BelongsTo<City, $this> */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /** @return HasMany<Block, $this> */
    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class);
    }

    /** @return HasMany<Block, $this> */
    public function activeBlocks(): HasMany
    {
        return $this->blocks()->where('is_active', true);
    }

    /** @return HasMany<Plot, $this> */
    public function plots(): HasMany
    {
        return $this->hasMany(Plot::class);
    }

    /**
     * @return array<string, \Illuminate\Database\Eloquent\Relations\Relation<*, *, *>>
     */
    protected function businessDependents(): array
    {
        return ['plots' => $this->plots()];
    }

    // --- Scopes ------------------------------------------------------

    /** @param  Builder<Project>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('name', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%")
                ->orWhere('address', 'like', "%{$term}%")
                ->orWhere('pincode', 'like', "%{$term}%");
        });
    }

    // --- Helpers ---------------------------------------------------

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function canTransitionTo(ProjectStatus $target): bool
    {
        return $this->status->canTransitionTo($target);
    }

    public function locationLabel(): string
    {
        return collect([$this->city?->name, $this->state?->name])
            ->filter()
            ->implode(', ') ?: '—';
    }

    /**
     * A URL-friendly, unique slug derived from the name.
     */
    public static function generateSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'project';
        $slug = $base;
        $suffix = 2;

        while (static::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
