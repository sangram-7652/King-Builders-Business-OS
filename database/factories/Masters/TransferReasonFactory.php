<?php

declare(strict_types=1);

namespace Database\Factories\Masters;

use App\Models\Masters\TransferReason;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TransferReason>
 */
class TransferReasonFactory extends Factory
{
    protected $model = TransferReason::class;

    public function definition(): array
    {
        return [
            'name' => Str::title(fake()->unique()->words(2, true)),
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
