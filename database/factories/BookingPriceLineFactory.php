<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PriceCalculationType;
use App\Enums\PriceComponentType;
use App\Models\Booking;
use App\Models\BookingPriceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingPriceLine>
 */
class BookingPriceLineFactory extends Factory
{
    protected $model = BookingPriceLine::class;

    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'type' => PriceComponentType::Charge->value,
            'name' => fake()->words(2, true),
            'calculation_type' => PriceCalculationType::Fixed->value,
            'quantity' => null,
            'rate' => fake()->randomFloat(2, 1000, 50000),
            'amount' => fn (array $a) => $a['rate'],
            'sort_order' => 0,
        ];
    }

    public function ofType(PriceComponentType $type): static
    {
        return $this->state(fn () => ['type' => $type->value, 'sort_order' => $type->sortWeight()]);
    }
}
