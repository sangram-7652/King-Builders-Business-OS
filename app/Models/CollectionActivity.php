<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CollectionActivityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only. `updated_at` is disabled; there is no factory — activities are
 * only ever written by CollectionCase::recordActivity().
 *
 * @property CollectionActivityType $type
 */
class CollectionActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['collection_case_id', 'booking_id', 'type', 'description', 'properties', 'causer_id', 'created_at'];

    protected function casts(): array
    {
        return [
            'collection_case_id' => 'integer',
            'booking_id' => 'integer',
            'causer_id' => 'integer',
            'type' => CollectionActivityType::class,
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CollectionCase, $this> */
    public function collectionCase(): BelongsTo
    {
        return $this->belongsTo(CollectionCase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }
}
