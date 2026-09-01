<?php

declare(strict_types=1);

namespace Database\Factories\Masters;

use App\Enums\Masters\LengthUnit;
use App\Models\Masters\PlotDimension;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlotDimension>
 */
class PlotDimensionFactory extends Factory
{
    protected $model = PlotDimension::class;

    public function definition(): array
    {
        $width = fake()->numberBetween(15, 60);
        $length = fake()->numberBetween(30, 90);

        return [
            'display_name' => "{$width} × {$length} ft",
            'width' => $width,
            'length' => $length,
            'unit' => LengthUnit::Feet->value,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
