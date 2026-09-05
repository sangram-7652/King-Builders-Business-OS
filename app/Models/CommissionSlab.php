<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommissionCalcType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bracket of a SLAB commission rule (M14.3).
 *
 * @property CommissionCalcType $calc_type only PERCENTAGE or FIXED
 */
class CommissionSlab extends Model
{
    protected $fillable = [
        'commission_rule_id', 'sort_order', 'from_amount', 'to_amount', 'calc_type', 'rate', 'flat_amount',
    ];

    protected function casts(): array
    {
        return [
            'commission_rule_id' => 'integer',
            'sort_order' => 'integer',
            'from_amount' => 'decimal:2',
            'to_amount' => 'decimal:2',
            'calc_type' => CommissionCalcType::class,
            'rate' => 'decimal:4',
            'flat_amount' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<CommissionRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }
}
