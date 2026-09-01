<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Enums\Masters\LengthUnit;
use Database\Factories\Masters\PlotDimensionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PlotDimension extends MasterModel
{
    /** @use HasFactory<PlotDimensionFactory> */
    use HasFactory;

    protected $fillable = ['display_name', 'width', 'length', 'unit', 'is_active', 'sort_order'];

    protected array $searchable = ['display_name'];

    protected string $displayColumn = 'display_name';

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'width' => 'decimal:2',
            'length' => 'decimal:2',
            'unit' => LengthUnit::class,
        ]);
    }
}
