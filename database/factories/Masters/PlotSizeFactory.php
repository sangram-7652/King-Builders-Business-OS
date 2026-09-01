<?php

declare(strict_types=1);

namespace Database\Factories\Masters;

use App\Enums\Masters\AreaUnit;
use App\Models\Masters\PlotSize;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlotSize>
 */
class PlotSizeFactory extends Factory
{
    protected $model = PlotSize::class;

    public function definition(): array
    {
        $sqYd = fake()->unique()->numberBetween(50, 500);

        return [
            'name' => "{$sqYd} sq yd",
            'area' => $sqYd * 9,
            'unit' => AreaUnit::SquareFeet->value,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
