<?php

declare(strict_types=1);

namespace Database\Factories\Masters;

use App\Models\Masters\PlotCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlotCategory>
 */
class PlotCategoryFactory extends Factory
{
    protected $model = PlotCategory::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'code' => strtoupper(Str::slug($name, '')),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(0, 50),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
