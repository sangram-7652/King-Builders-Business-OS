<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LeadActivityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only. `updated_at` is disabled; there is no factory — activities are
 * only ever written by Lead::recordActivity().
 *
 * @property LeadActivityType $type
 */
class LeadActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['lead_id', 'type', 'description', 'properties', 'causer_id', 'created_at'];

    protected function casts(): array
    {
        return [
            'lead_id' => 'integer',
            'causer_id' => 'integer',
            'type' => LeadActivityType::class,
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<User, $this> */
    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }
}
