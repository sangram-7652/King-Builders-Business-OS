<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'code' => strtoupper(Str::random(2).fake()->unique()->numberBetween(10, 99)),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'description' => fake()->optional()->paragraph(),
            'status' => ProjectStatus::Planning,
            'is_active' => true,
            'address' => fake()->optional()->streetAddress(),
            'state_id' => null,
            'city_id' => null,
            'pincode' => fake()->optional()->numerify('######'),
            'latitude' => fake()->optional()->latitude(),
            'longitude' => fake()->optional()->longitude(),
            'contact_name' => fake()->optional()->name(),
            'contact_phone' => fake()->optional()->numerify('+9198########'),
            'contact_email' => fake()->optional()->safeEmail(),
            'launch_date' => fake()->optional()->dateTimeBetween('-1 year', '+1 year')?->format('Y-m-d'),
        ];
    }

    public function status(ProjectStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
