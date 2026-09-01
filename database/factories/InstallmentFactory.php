<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InstallmentStatus;
use App\Models\Installment;
use App\Models\PaymentPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Installment>
 */
class InstallmentFactory extends Factory
{
    protected $model = Installment::class;

    public function definition(): array
    {
        return [
            'payment_plan_id' => PaymentPlan::factory(),
            'installment_number' => fn (array $a) => (Installment::where('payment_plan_id', $a['payment_plan_id'])->max('installment_number') ?? 0) + 1,
            'name' => null,
            'due_date' => now()->addMonth()->toDateString(),
            'amount' => 100000,
            'status' => InstallmentStatus::Upcoming->value,
        ];
    }

    public function dueOn(string $date): static
    {
        return $this->state(fn () => ['due_date' => $date]);
    }
}
