<?php

declare(strict_types=1);

namespace Database\Factories\Masters;

use App\Models\Masters\State;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<State>
 */
class StateFactory extends Factory
{
    protected $model = State::class;

    public function definition(): array
    {
        $name = fake()->unique()->state();

        return [
            'name' => $name,
            'code' => strtoupper(Str::substr(Str::slug($name, ''), 0, 2)).fake()->unique()->numberBetween(1, 99),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
