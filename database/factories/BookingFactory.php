<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Block;
use App\Models\Booking;
use App\Models\Plot;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        return [
            'booking_number' => 'BK-'.fake()->unique()->numerify('######'),
            'plot_id' => Plot::factory(),
            'project_id' => fn (array $attributes) => Plot::find($attributes['plot_id'])?->project_id ?? Project::factory(),
            'block_id' => fn (array $attributes) => Plot::find($attributes['plot_id'])?->block_id ?? Block::factory(),
            'booking_date' => now()->toDateString(),
            'status' => BookingStatus::Draft->value,
            'base_area' => 0,
            'base_rate' => 0,
            'base_amount' => 0,
            'plc_amount' => 0,
            'charge_amount' => 0,
            'subtotal' => 0,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'final_amount' => 0,
            'notes' => null,
        ];
    }

    public function forPlot(Plot $plot): static
    {
        return $this->state(fn () => [
            'plot_id' => $plot->id,
            'project_id' => $plot->project_id,
            'block_id' => $plot->block_id,
        ]);
    }

    public function status(BookingStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    public function pending(): static
    {
        return $this->status(BookingStatus::Pending);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => BookingStatus::Confirmed->value,
            'confirmed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => BookingStatus::Cancelled->value,
            'cancelled_at' => now(),
        ]);
    }
}
