<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommissionCalcType;
use App\Enums\SlabMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One calculation rule within a commission scheme version (M14.3).
 * `project_id === null` is the scheme default; a set `project_id` overrides it.
 *
 * @property CommissionCalcType $calc_type
 * @property SlabMode|null $slab_mode
 */
class CommissionRule extends Model
{
    protected $fillable = [
        'commission_scheme_id', 'project_id', 'calc_type', 'rate', 'flat_amount',
        'slab_mode', 'min_amount', 'max_amount', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'commission_scheme_id' => 'integer',
            'project_id' => 'integer',
            'calc_type' => CommissionCalcType::class,
            'rate' => 'decimal:4',
            'flat_amount' => 'decimal:2',
            'slab_mode' => SlabMode::class,
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<CommissionScheme, $this> */
    public function scheme(): BelongsTo
    {
        return $this->belongsTo(CommissionScheme::class, 'commission_scheme_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<CommissionSlab, $this> */
    public function slabs(): HasMany
    {
        return $this->hasMany(CommissionSlab::class)->orderBy('sort_order')->orderBy('from_amount');
    }

    public function isSlab(): bool
    {
        return $this->calc_type === CommissionCalcType::Slab;
    }
}
