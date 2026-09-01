<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Enums\Masters\PlcCalculationType;
use Database\Factories\Masters\PlcTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PlcType extends MasterModel
{
    /** @use HasFactory<PlcTypeFactory> */
    use HasFactory;

    protected $fillable = ['name', 'code', 'calculation_type', 'value', 'description', 'is_active', 'sort_order'];

    protected array $searchable = ['name', 'code', 'description'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'calculation_type' => PlcCalculationType::class,
            'value' => 'decimal:2',
        ]);
    }
}
