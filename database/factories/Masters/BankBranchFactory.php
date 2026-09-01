<?php

declare(strict_types=1);

namespace Database\Factories\Masters;

use App\Models\Masters\Bank;
use App\Models\Masters\BankBranch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BankBranch>
 */
class BankBranchFactory extends Factory
{
    protected $model = BankBranch::class;

    public function definition(): array
    {
        return [
            'bank_id' => Bank::factory(),
            'name' => Str::title(fake()->city()).' Branch',
            'ifsc' => strtoupper(fake()->unique()->bothify('????0??####')),
            'address' => fake()->address(),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
