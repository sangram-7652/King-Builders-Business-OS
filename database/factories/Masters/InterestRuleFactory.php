<?php

declare(strict_types=1);

namespace Database\Factories\Masters;

use App\Enums\Masters\InterestFrequency;
use App\Enums\Masters\InterestType;
use App\Models\Masters\InterestRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InterestRule>
 */
class InterestRuleFactory extends Factory
{
    protected $model = InterestRule::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true).' interest',
            'interest_type' => fake()->randomElement(InterestType::cases())->value,
            'rate' => fake()->randomFloat(4, 6, 24),
            'frequency' => fake()->randomElement(InterestFrequency::cases())->value,
            'grace_period_days' => fake()->numberBetween(0, 30),
            'effective_from' => fake()->dateTimeBetween('-4 years', '-1 year')->format('Y-m-d'),
            'effective_until' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
