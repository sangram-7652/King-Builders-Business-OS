<?php

declare(strict_types=1);

namespace App\Models\Masters;

use App\Enums\Masters\InterestFrequency;
use App\Enums\Masters\InterestType;
use Database\Factories\Masters\InterestRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InterestRule extends MasterModel
{
    /** @use HasFactory<InterestRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'interest_type', 'rate', 'frequency',
        'grace_period_days', 'effective_from', 'effective_until',
        'is_active', 'sort_order',
    ];

    protected array $searchable = ['name'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'interest_type' => InterestType::class,
            'frequency' => InterestFrequency::class,
            'rate' => 'decimal:4',
            'grace_period_days' => 'integer',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ]);
    }
}
