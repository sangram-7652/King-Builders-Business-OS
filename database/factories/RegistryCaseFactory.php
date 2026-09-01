<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RegistryCaseStatus;
use App\Models\Booking;
use App\Models\RegistryCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RegistryCase> */
class RegistryCaseFactory extends Factory
{
    protected $model = RegistryCase::class;

    public function definition(): array
    {
        return [
            'case_number' => 'REG-'.fake()->unique()->numerify('######'),
            'booking_id' => Booking::factory()->confirmed(),
            'status' => RegistryCaseStatus::NotStarted->value,
            'initiated_at' => now(),
        ];
    }

    public function status(RegistryCaseStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    public function forBooking(Booking $booking): static
    {
        return $this->state(fn () => ['booking_id' => $booking->id]);
    }
}
