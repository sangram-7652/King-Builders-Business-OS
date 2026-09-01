<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Models\Plot;
use Database\Factories\Masters\PlotCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlotCategory extends MasterModel
{
    /** @use HasFactory<PlotCategoryFactory> */
    use HasFactory;

    protected $fillable = ['name', 'code', 'description', 'is_active', 'sort_order'];

    protected array $searchable = ['name', 'code', 'description'];

    /** @return HasMany<Plot, $this> */
    public function plots(): HasMany
    {
        return $this->hasMany(Plot::class, 'plot_category_id');
    }

    /** @return list<string> */
    public function referencingRelations(): array
    {
        return ['plots'];
    }
}
