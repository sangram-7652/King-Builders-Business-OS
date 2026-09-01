<?php

declare(strict_types=1);

namespace Database\Factories\Masters;

use App\Enums\PriceCalculationType;
use App\Models\Masters\ChargeType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChargeType>
 */
class ChargeTypeFactory extends Factory
{
    protected $model = ChargeType::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);
        $type = fake()->randomElement(PriceCalculationType::cases());

        return [
            'name' => Str::title($name),
            'code' => strtoupper(Str::slug($name, '')),
            'calculation_type' => $type->value,
            'value' => $type === PriceCalculationType::Percentage
                ? fake()->randomFloat(2, 1, 15)
                : fake()->randomFloat(2, 50, 100000),
            'description' => fake()->optional()->sentence(),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function fixed(float $amount = 25000): static
    {
        return $this->state(fn () => [
            'calculation_type' => PriceCalculationType::Fixed->value,
            'value' => $amount,
        ]);
    }

    public function percentage(float $percent = 2): static
    {
        return $this->state(fn () => [
            'calculation_type' => PriceCalculationType::Percentage->value,
            'value' => $percent,
        ]);
    }

    public function perSqft(float $rate = 50): static
    {
        return $this->state(fn () => [
            'calculation_type' => PriceCalculationType::PerSqft->value,
            'value' => $rate,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
