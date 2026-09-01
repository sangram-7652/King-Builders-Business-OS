<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentActivityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only (M9). `updated_at` disabled; no factory — written only via
 * App\Support\Documents\DocumentTimeline.
 *
 * @property DocumentActivityType $type
 */
class DocumentActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['booking_id', 'buyer_id', 'type', 'description', 'properties', 'causer_id', 'created_at'];

    protected function casts(): array
    {
        return [
            'booking_id' => 'integer',
            'buyer_id' => 'integer',
            'causer_id' => 'integer',
            'type' => DocumentActivityType::class,
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Buyer, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }
}
