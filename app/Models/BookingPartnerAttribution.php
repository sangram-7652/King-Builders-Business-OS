<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingAttributionRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One partner's share of a booking (M14.2). The `active` rows for a booking are
 * the current split (shares total 100.00, one primary); `superseded` rows are
 * previous splits kept for history and commission-snapshot reproducibility.
 *
 * @property BookingAttributionRole $role
 */
class BookingPartnerAttribution extends Model
{
    protected $fillable = [
        'booking_id', 'partner_id', 'share_percentage', 'role', 'status', 'revision',
        'attributed_by', 'attributed_at', 'ended_at', 'reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'booking_id' => 'integer',
            'partner_id' => 'integer',
            'share_percentage' => 'decimal:2',
            'role' => BookingAttributionRole::class,
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

    public function isPrimary(): bool
    {
        return $this->role === BookingAttributionRole::Primary;
    }
}
