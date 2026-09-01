<?php

declare(strict_types=1);

namespace App\Models\Masters;

use Database\Factories\Masters\PlotCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PlotCategory extends MasterModel
{
    /** @use HasFactory<PlotCategoryFactory> */
    use HasFactory;

    protected $fillable = ['name', 'code', 'description', 'is_active', 'sort_order'];

    protected array $searchable = ['name', 'code', 'description'];
}
