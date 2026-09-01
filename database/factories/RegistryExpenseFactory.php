<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RegistryExpenseType;
use App\Models\RegistryCase;
use App\Models\RegistryExpense;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RegistryExpense> */
class RegistryExpenseFactory extends Factory
{
    protected $model = RegistryExpense::class;

    public function definition(): array
    {
        return [
            'registry_case_id' => RegistryCase::factory(),
            'booking_id' => fn (array $a) => RegistryCase::find($a['registry_case_id'])?->booking_id,
            'expense_type' => RegistryExpenseType::StampDuty->value,
            'amount' => 50000,
            'status' => RegistryExpense::STATUS_RECORDED,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => RegistryExpense::STATUS_APPROVED, 'approved_at' => now()]);
    }
}
