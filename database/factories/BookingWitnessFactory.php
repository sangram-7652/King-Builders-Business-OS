<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Booking;
use App\Models\BookingWitness;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingWitness>
 */
class BookingWitnessFactory extends Factory
{
    protected $model = BookingWitness::class;

    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'witness_number' => 1,
            'name' => $this->faker->name(),
            'address' => $this->faker->address(),
            'mobile' => $this->faker->numerify('##########'),
        ];
    }
}
