<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\GuardsAgainstDestructiveDelete;
use Database\Factories\BlockFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property bool $is_active
 * @property int $sort_order
 */
class Block extends Model
{
    /** @use HasFactory<BlockFactory> */
    use GuardsAgainstDestructiveDelete, HasFactory, SoftDeletes;

    protected $fillable = [
        'project_id', 'name', 'code', 'description', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @param  Builder<Block>  $query */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('name', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%");
        });
    }

    /** @param  Builder<Block>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }
}
