<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommissionCaseEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only (M14.4). `updated_at` disabled; written only via
 * CommissionCase::recordEvent().
 *
 * @property CommissionCaseEventType $type
 */
class CommissionCaseEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['commission_case_id', 'type', 'description', 'properties', 'causer_id', 'created_at'];

    protected function casts(): array
    {
        return [
            'commission_case_id' => 'integer',
            'causer_id' => 'integer',
            'type' => CommissionCaseEventType::class,
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CommissionCase, $this> */
    public function commissionCase(): BelongsTo
    {
        return $this->belongsTo(CommissionCase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }
}
