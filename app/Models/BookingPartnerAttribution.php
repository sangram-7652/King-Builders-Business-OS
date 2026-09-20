<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A booking's promoter attribution (one promoter maximum per booking). The
 * `active` row (at most one per booking) is the current promoter; `superseded`
 * rows are previous attributions kept for history and commission-snapshot
 * reproducibility.
 *
 * `commission_percentage` is a SNAPSHOT of the promoter's rate taken the
 * moment they were attached to this booking (see SetBookingPartnerAttribution) —
 * commission for this booking always uses this frozen rate, never the
 * promoter's current master rate, so a later change to the promoter's profile
 * can never silently drift an already-attributed booking's commission.
 * Nullable only for rows written before this snapshot existed.
 */
class BookingPartnerAttribution extends Model
{
    protected $fillable = [
        'booking_id', 'partner_id', 'commission_percentage', 'status', 'revision',
        'attributed_by', 'attributed_at', 'ended_at', 'reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'booking_id' => 'integer',
            'partner_id' => 'integer',
            'commission_percentage' => 'decimal:2',
            'revision' => 'integer',
            'attributed_by' => 'integer',
            'attributed_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<User, $this> */
    public function attributedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attributed_by');
    }

    /** @param  Builder<BookingPartnerAttribution>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
