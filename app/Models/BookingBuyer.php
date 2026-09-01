<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BookingBuyerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One buyer's stake in a booking (M6). Co-ownership lives here, never as a
 * `buyer_id` on `bookings`.
 */
class BookingBuyer extends Model
{
    /** @use HasFactory<BookingBuyerFactory> */
    use HasFactory;

    protected $table = 'booking_buyers';

    protected $fillable = ['booking_id', 'buyer_id', 'ownership_percentage', 'is_primary'];

    protected function casts(): array
    {
        return [
            'booking_id' => 'integer',
            'buyer_id' => 'integer',
            'ownership_percentage' => 'decimal:2',
            'is_primary' => 'boolean',
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
}
