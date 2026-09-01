<?php

declare(strict_types=1);

namespace Database\Factories\Masters;

use App\Models\Masters\TdsRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TdsRule>
 */
class TdsRuleFactory extends Factory
{
    protected $model = TdsRule::class;

    public function definition(): array
    {
        return [
            'name' => 'TDS '.fake()->unique()->numerify('Rule ###'),
            'percentage' => fake()->randomFloat(2, 0.5, 20),
            'applicable_from' => fake()->dateTimeBetween('-5 years', '-1 year')->format('Y-m-d'),
            'applicable_until' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
