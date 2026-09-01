<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PenaltyStatus;
use Database\Factories\BouncePenaltyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bounce penalty (M8) — assessment foundation. Explicitly assessed and
 * authorised; never auto-posted to the M7 booking total.
 *
 * @property PenaltyStatus $status
 */
class BouncePenalty extends Model
{
    /** @use HasFactory<BouncePenaltyFactory> */
    use HasFactory;

    protected $fillable = [
        'cheque_bounce_id', 'booking_id', 'penalty_amount', 'reason', 'status',
        'assessed_by', 'assessed_at', 'approved_by', 'approved_at',
        'cancelled_by', 'cancelled_at', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'cheque_bounce_id' => 'integer',
            'booking_id' => 'integer',
            'penalty_amount' => 'decimal:2',
            'status' => PenaltyStatus::class,
            'assessed_by' => 'integer',
            'assessed_at' => 'datetime',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'cancelled_by' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ChequeBounce, $this> */
    public function chequeBounce(): BelongsTo
    {
        return $this->belongsTo(ChequeBounce::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->status === PenaltyStatus::Approved;
    }
}
