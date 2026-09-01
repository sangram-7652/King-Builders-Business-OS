<?php

declare(strict_types=1);

namespace Database\Factories\Masters;

use App\Models\Masters\TaxRate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TaxRate>
 */
class TaxRateFactory extends Factory
{
    protected $model = TaxRate::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'code' => strtoupper(Str::slug($name, '')),
            'percentage' => fake()->randomElement([1, 5, 12, 18]),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function rate(float $percentage): static
    {
        return $this->state(fn () => ['percentage' => $percentage]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
