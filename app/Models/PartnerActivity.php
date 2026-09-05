<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PartnerActivityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only (M14). `updated_at` is disabled; there is no factory — activities
 * are only ever written by Partner::recordActivity().
 *
 * @property PartnerActivityType $type
 */
class PartnerActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['partner_id', 'type', 'description', 'properties', 'causer_id', 'created_at'];

    protected function casts(): array
    {
        return [
            'partner_id' => 'integer',
            'causer_id' => 'integer',
            'type' => PartnerActivityType::class,
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<User, $this> */
    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }
}
