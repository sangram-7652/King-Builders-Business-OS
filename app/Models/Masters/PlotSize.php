<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Enums\Masters\AreaUnit;
use App\Models\Plot;
use Database\Factories\Masters\PlotSizeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlotSize extends MasterModel
{
    /** @use HasFactory<PlotSizeFactory> */
    use HasFactory;

    protected $fillable = ['name', 'area', 'unit', 'is_active', 'sort_order'];

    protected array $searchable = ['name'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'area' => 'decimal:2',
            'unit' => AreaUnit::class,
        ]);
    }

    /** @return HasMany<Plot, $this> */
    public function plots(): HasMany
    {
        return $this->hasMany(Plot::class, 'plot_size_id');
    }

    /** @return list<string> */
    public function referencingRelations(): array
    {
        return ['plots'];
    }
}
