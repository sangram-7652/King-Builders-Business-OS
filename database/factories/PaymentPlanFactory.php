<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentPlanStatus;
use App\Models\Booking;
use App\Models\PaymentPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentPlan>
 */
class PaymentPlanFactory extends Factory
{
    protected $model = PaymentPlan::class;

    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory()->confirmed(),
            'name' => 'Payment plan',
            'total_amount' => 1000000,
            'status' => PaymentPlanStatus::Draft->value,
            'allows_variance' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => PaymentPlanStatus::Active->value, 'activated_at' => now()]);
    }

    public function forBooking(Booking $booking): static
    {
        return $this->state(fn () => ['booking_id' => $booking->id, 'total_amount' => $booking->final_amount]);
    }
}
