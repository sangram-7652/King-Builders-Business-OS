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
 */
class BookingPartnerAttribution extends Model
{
    protected $fillable = [
        'booking_id', 'partner_id', 'status', 'revision',
        'attributed_by', 'attributed_at', 'ended_at', 'reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'booking_id' => 'integer',
            'partner_id' => 'integer',
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
