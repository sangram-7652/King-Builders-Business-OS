<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OwnershipType;
use Database\Factories\PlotOwnershipHistoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One plot ownership period (M10). Append-only: `ended_at` NULL = current owner.
 * The original allotment period(s) come from `booking_buyers`; a completed
 * transfer closes the active period(s) and opens new ones.
 *
 * @property OwnershipType $ownership_type
 */
class PlotOwnershipHistory extends Model
{
    /** @use HasFactory<PlotOwnershipHistoryFactory> */
    use HasFactory;

    protected $table = 'plot_ownership_history';

    protected $fillable = [
        'plot_id', 'booking_id', 'buyer_id', 'ownership_type', 'is_primary', 'ownership_percentage',
        'started_at', 'ended_at', 'source_type', 'source_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'plot_id' => 'integer',
            'booking_id' => 'integer',
            'buyer_id' => 'integer',
            'ownership_type' => OwnershipType::class,
            'is_primary' => 'boolean',
            'ownership_percentage' => 'decimal:2',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'source_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Plot, $this> */
    public function plot(): BelongsTo
    {
        return $this->belongsTo(Plot::class);
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

    /** @param  Builder<PlotOwnershipHistory>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }
}
