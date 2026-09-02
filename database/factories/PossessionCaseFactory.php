<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PossessionCaseStatus;
use App\Models\Booking;
use App\Models\PossessionCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PossessionCase> */
class PossessionCaseFactory extends Factory
{
    protected $model = PossessionCase::class;

    public function definition(): array
    {
        return [
            'case_number' => 'POS-'.fake()->unique()->numerify('######'),
            'booking_id' => Booking::factory()->confirmed(),
            'plot_id' => fn (array $a) => Booking::find($a['booking_id'])?->plot_id,
            'status' => PossessionCaseStatus::NotStarted->value,
            'initiated_at' => now(),
        ];
    }

    public function status(PossessionCaseStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    public function forBooking(Booking $booking): static
    {
        return $this->state(fn () => ['booking_id' => $booking->id, 'plot_id' => $booking->plot_id]);
    }
}
