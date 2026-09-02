<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Buyer;
use App\Models\BuyerNominee;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BuyerNominee> */
class BuyerNomineeFactory extends Factory
{
    protected $model = BuyerNominee::class;

    public function definition(): array
    {
        return [
            'buyer_id' => Buyer::factory()->state(['status' => 'active']),
            'name' => fake()->name(),
            'relation' => fake()->randomElement(['Spouse', 'Child', 'Parent', 'Sibling']),
            'phone' => fake()->numerify('9#########'),
            'status' => BuyerNominee::STATUS_ACTIVE,
        ];
    }

    public function superseded(): static
    {
        return $this->state(fn () => ['status' => BuyerNominee::STATUS_SUPERSEDED]);
    }
}
