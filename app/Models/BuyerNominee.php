<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BuyerNomineeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Buyer nominee foundation (M10). A nominee is NOT an owner. A nominee change
 * supersedes the active row (append-only) rather than editing it.
 */
class BuyerNominee extends Model
{
    /** @use HasFactory<BuyerNomineeFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'buyer_id', 'booking_id', 'name', 'relation', 'phone', 'share_percentage',
        'status', 'id_document_id', 'superseded_by', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'buyer_id' => 'integer',
            'booking_id' => 'integer',
            'share_percentage' => 'decimal:2',
            'id_document_id' => 'integer',
            'superseded_by' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Buyer, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @param  Builder<BuyerNominee>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
