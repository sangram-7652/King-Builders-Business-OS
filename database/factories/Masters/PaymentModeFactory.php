<?php

declare(strict_types=1);

namespace Database\Factories\Masters;

use App\Models\Masters\PaymentMode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentMode>
 */
class PaymentModeFactory extends Factory
{
    protected $model = PaymentMode::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::title($name),
            'code' => strtoupper(Str::slug($name, '_')),
            'description' => fake()->optional()->sentence(),
            'requires_reference' => fake()->boolean(),
            'is_cheque' => false,
            'is_active' => true,
            'is_system' => false,
            'sort_order' => 0,
        ];
    }

    public function system(): static
    {
        return $this->state(fn () => ['is_system' => true]);
    }

    public function cheque(): static
    {
        return $this->state(fn () => ['is_cheque' => true, 'requires_reference' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
