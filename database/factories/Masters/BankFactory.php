<?php

declare(strict_types=1);

namespace Database\Factories\Masters;

use App\Models\Masters\Bank;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Bank>
 */
class BankFactory extends Factory
{
    protected $model = Bank::class;

    public function definition(): array
    {
        $name = Str::title(fake()->unique()->company()).' Bank';

        return [
            'name' => $name,
            'code' => strtoupper(Str::substr(Str::slug($name, ''), 0, 8)).fake()->unique()->numberBetween(1, 999),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
