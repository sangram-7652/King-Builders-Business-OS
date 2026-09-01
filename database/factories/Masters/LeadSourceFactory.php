<?php

declare(strict_types=1);

namespace Database\Factories\Masters;

use App\Models\Masters\LeadSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LeadSource>
 */
class LeadSourceFactory extends Factory
{
    protected $model = LeadSource::class;

    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'code' => strtoupper(Str::slug($name, '_')),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
            'is_system' => false,
            'sort_order' => 0,
        ];
    }

    public function system(): static
    {
        return $this->state(fn () => ['is_system' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
