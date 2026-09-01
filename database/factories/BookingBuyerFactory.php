<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Booking;
use App\Models\BookingBuyer;
use App\Models\Buyer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingBuyer>
 */
class BookingBuyerFactory extends Factory
{
    protected $model = BookingBuyer::class;

    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'buyer_id' => Buyer::factory(),
            'ownership_percentage' => 100,
            'is_primary' => true,
        ];
    }

    public function secondary(float $percentage = 50): static
    {
        return $this->state(fn () => [
            'ownership_percentage' => $percentage,
            'is_primary' => false,
        ]);
    }
}
