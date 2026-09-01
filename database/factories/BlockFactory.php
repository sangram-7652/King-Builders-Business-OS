<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Block;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Block>
 */
class BlockFactory extends Factory
{
    protected $model = Block::class;

    public function definition(): array
    {
        $letter = fake()->unique()->randomElement(range('A', 'Z'));

        return [
            'project_id' => Project::factory(),
            'name' => "Block {$letter}",
            'code' => $letter,
            'description' => fake()->optional()->sentence(),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
