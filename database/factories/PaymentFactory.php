<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Masters\PaymentMode;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'payment_number' => 'PAY-'.fake()->unique()->numerify('######'),
            'booking_id' => Booking::factory()->confirmed(),
            'payment_mode_id' => fn () => PaymentMode::query()->where('code', 'CASH')->value('id')
                ?? PaymentMode::factory()->create(['code' => 'CASH', 'name' => 'Cash'])->id,
            'payment_date' => now()->toDateString(),
            'amount' => 100000,
            'status' => PaymentStatus::Pending->value,
            'reference_number' => null,
        ];
    }

    public function successful(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Success->value,
            'verified_at' => now(),
        ]);
    }

    public function forBooking(Booking $booking): static
    {
        return $this->state(fn () => ['booking_id' => $booking->id]);
    }
}
