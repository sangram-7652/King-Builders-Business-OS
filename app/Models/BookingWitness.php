<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BookingWitnessFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Witness 1 / Witness 2 on a booking's Plot KYC Receipt (registry KYC).
 * Optional — a booking may have zero, one, or two of these.
 */
class BookingWitness extends Model
{
    /** @use HasFactory<BookingWitnessFactory> */
    use HasFactory;

    protected $fillable = [
        'booking_id', 'witness_number', 'name', 'address', 'mobile',
    ];

    protected function casts(): array
    {
        return [
            'booking_id' => 'integer',
            'witness_number' => 'integer',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
