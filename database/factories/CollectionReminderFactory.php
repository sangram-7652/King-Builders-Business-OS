<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CollectionReminderType;
use App\Models\Booking;
use App\Models\CollectionReminder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CollectionReminder>
 */
class CollectionReminderFactory extends Factory
{
    protected $model = CollectionReminder::class;

    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory()->confirmed(),
            'type' => CollectionReminderType::Overdue->value,
            'remind_on' => now()->toDateString(),
            'message' => 'Installment overdue.',
            'status' => CollectionReminder::STATUS_PENDING,
        ];
    }
}
