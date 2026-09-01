<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Installment;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAllocation>
 */
class PaymentAllocationFactory extends Factory
{
    protected $model = PaymentAllocation::class;

    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory()->successful(),
            'installment_id' => Installment::factory(),
            'amount' => 100000,
            'is_auto' => true,
        ];
    }
}
