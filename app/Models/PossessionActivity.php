<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PossessionActivityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only (M10). `updated_at` disabled; no factory — written only via
 * App\Support\Possession\PossessionTimeline.
 *
 * @property PossessionActivityType $type
 */
class PossessionActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['booking_id', 'plot_id', 'buyer_id', 'type', 'description', 'properties', 'causer_id', 'created_at'];

    protected function casts(): array
    {
        return [
            'booking_id' => 'integer',
            'plot_id' => 'integer',
            'buyer_id' => 'integer',
            'causer_id' => 'integer',
            'type' => PossessionActivityType::class,
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Plot, $this> */
    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class);
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
