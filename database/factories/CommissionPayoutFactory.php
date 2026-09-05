<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CommissionPayoutMethod;
use App\Enums\CommissionPayoutStatus;
use App\Models\CommissionCase;
use App\Models\CommissionPayout;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionPayout>
 */
class CommissionPayoutFactory extends Factory
{
    protected $model = CommissionPayout::class;

    public function definition(): array
    {
        return [
            'commission_case_id' => CommissionCase::factory(),
            'amount' => '10000.00',
            'method' => CommissionPayoutMethod::BankTransfer->value,
            'paid_on' => now()->toDateString(),
            'reference' => fake()->bothify('UTR#########'),
            'status' => CommissionPayoutStatus::Recorded->value,
        ];
    }

    public function voided(): static
    {
        return $this->state(fn () => [
            'status' => CommissionPayoutStatus::Voided->value,
            'voided_at' => now(),
            'void_reason' => 'Recorded in error',
        ]);
    }
}
