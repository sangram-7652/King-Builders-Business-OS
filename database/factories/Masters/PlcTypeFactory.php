<?php

declare(strict_types=1);

namespace Database\Factories\Masters;

use App\Enums\Masters\PlcCalculationType;
use App\Models\Masters\PlcType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlcType>
 */
class PlcTypeFactory extends Factory
{
    protected $model = PlcType::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);
        $type = fake()->randomElement(PlcCalculationType::cases());

        return [
            'name' => Str::title($name),
            'code' => strtoupper(Str::slug($name, '')),
            'calculation_type' => $type->value,
            'value' => $type === PlcCalculationType::Percentage
                ? fake()->randomFloat(2, 1, 25)
                : fake()->randomFloat(2, 50, 5000),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function percentage(): static
    {
        return $this->state(fn () => [
            'calculation_type' => PlcCalculationType::Percentage->value,
            'value' => fake()->randomFloat(2, 1, 25),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
