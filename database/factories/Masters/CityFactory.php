<?php

declare(strict_types=1);

namespace Database\Factories\Masters;

use App\Models\Masters\City;
use App\Models\Masters\State;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    protected $model = City::class;

    public function definition(): array
    {
        return [
            'state_id' => State::factory(),
            'name' => fake()->unique()->city(),
            'code' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
