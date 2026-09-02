<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OwnershipType;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\PlotOwnershipHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlotOwnershipHistory> */
class PlotOwnershipHistoryFactory extends Factory
{
    protected $model = PlotOwnershipHistory::class;

    public function definition(): array
    {
        $booking = Booking::factory()->confirmed();

        return [
            'booking_id' => $booking,
            'plot_id' => fn (array $a) => Booking::find($a['booking_id'])?->plot_id,
            'buyer_id' => Buyer::factory()->state(['status' => 'active']),
            'ownership_type' => OwnershipType::Allotment->value,
            'is_primary' => true,
            'ownership_percentage' => 100,
            'started_at' => now()->subMonths(2),
            'ended_at' => null,
        ];
    }

    public function ended(): static
    {
        return $this->state(fn () => ['ended_at' => now()]);
    }
}
