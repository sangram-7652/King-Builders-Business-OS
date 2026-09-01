<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ChequeBounceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Metadata for a bounced cheque payment (M8). The M7 payment itself is FAILED
 * or REVERSED through M7 — never deleted.
 */
class ChequeBounce extends Model
{
    /** @use HasFactory<ChequeBounceFactory> */
    use HasFactory;

    protected $fillable = [
        'payment_id', 'booking_id', 'collection_case_id',
        'bounce_date', 'bounce_reason', 'bank_charges', 'notes', 'payment_was_cleared', 'handled_by',
    ];

    protected function casts(): array
    {
        return [
            'payment_id' => 'integer',
            'booking_id' => 'integer',
            'collection_case_id' => 'integer',
            'bounce_date' => 'date',
            'bank_charges' => 'decimal:2',
            'payment_was_cleared' => 'boolean',
            'handled_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<CollectionCase, $this> */
    public function collectionCase(): BelongsTo
    {
        return $this->belongsTo(CollectionCase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /** @return HasMany<BouncePenalty, $this> */
    public function penalties(): HasMany
    {
        return $this->hasMany(BouncePenalty::class);
    }
}
