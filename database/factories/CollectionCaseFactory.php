<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CollectionCaseStatus;
use App\Enums\CollectionPriority;
use App\Models\Booking;
use App\Models\CollectionCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CollectionCase>
 */
class CollectionCaseFactory extends Factory
{
    protected $model = CollectionCase::class;

    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory()->confirmed(),
            'status' => CollectionCaseStatus::Open->value,
            'priority' => CollectionPriority::Low->value,
            'opened_at' => now(),
        ];
    }

    public function forBooking(Booking $booking): static
    {
        return $this->state(fn () => ['booking_id' => $booking->id]);
    }

    public function assignedTo(int $userId): static
    {
        return $this->state(fn () => ['assigned_to' => $userId, 'status' => CollectionCaseStatus::InProgress->value]);
    }
}
