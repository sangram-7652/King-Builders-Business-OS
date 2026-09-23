<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Masters\AreaUnit;
use App\Enums\PlotFacing;
use App\Enums\PlotStatus;
use App\Models\Block;
use App\Models\Plot;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plot>
 */
class PlotFactory extends Factory
{
    protected $model = Plot::class;

    public function definition(): array
    {
        return [
            'block_id' => Block::factory(),
            // Keep the plot's project in sync with its block's project.
            'project_id' => fn (array $attributes) => Block::find($attributes['block_id'])?->project_id
                ?? Project::factory(),
            'plot_number' => (string) fake()->unique()->numberBetween(1, 100000),
            'plot_category_id' => null,
            'plot_size_id' => null,
            'plot_dimension_id' => null,
            'area' => fake()->randomFloat(2, 450, 5000),
            'area_unit' => AreaUnit::SquareFeet->value,
            'facing' => fake()->optional()->randomElement(PlotFacing::cases())?->value,
            'status' => PlotStatus::Available->value,
            'is_active' => true,
        ];
    }

    /** Keep the plot inside an existing block (and its project). */
    public function forBlock(Block $block): static
    {
        return $this->state(fn () => [
            'project_id' => $block->project_id,
            'block_id' => $block->id,
        ]);
    }

    /** A direct project plot — no Block (a Block is always OPTIONAL). */
    public function direct(): static
    {
        return $this->state(fn () => [
            'block_id' => null,
            'project_id' => Project::factory(),
        ]);
    }

    public function status(PlotStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    public function available(): static
    {
        return $this->status(PlotStatus::Available);
    }

    public function onHold(?\DateTimeInterface $expiresAt = null): static
    {
        return $this->state(fn () => [
            'status' => PlotStatus::Hold->value,
            'held_at' => now(),
            'hold_expires_at' => $expiresAt,
            'hold_reason' => 'Reserved for walk-in',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
