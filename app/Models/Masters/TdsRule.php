<?php

declare(strict_types=1);

namespace App\Models\Masters;

use Database\Factories\Masters\TdsRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TdsRule extends MasterModel
{
    /** @use HasFactory<TdsRuleFactory> */
    use HasFactory;

    protected $fillable = ['name', 'percentage', 'applicable_from', 'applicable_until', 'is_active', 'sort_order'];

    protected array $searchable = ['name'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'percentage' => 'decimal:2',
            'applicable_from' => 'date',
            'applicable_until' => 'date',
        ]);
    }
}
