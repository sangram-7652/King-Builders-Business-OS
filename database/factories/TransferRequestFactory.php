<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TransferRequestStatus;
use App\Enums\TransferType;
use App\Models\Booking;
use App\Models\Buyer;
use App\Models\TransferRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TransferRequest> */
class TransferRequestFactory extends Factory
{
    protected $model = TransferRequest::class;

    public function definition(): array
    {
        return [
            'request_number' => 'TRF-'.fake()->unique()->numerify('######'),
            'booking_id' => Booking::factory()->confirmed(),
            'plot_id' => fn (array $a) => Booking::find($a['booking_id'])?->plot_id,
            'transfer_type' => TransferType::SaleTransfer->value,
            'status' => TransferRequestStatus::Draft->value,
            'new_buyer_id' => Buyer::factory()->state(['status' => 'active']),
            'requested_at' => now(),
        ];
    }

    public function status(TransferRequestStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    public function forBooking(Booking $booking): static
    {
        return $this->state(fn () => ['booking_id' => $booking->id, 'plot_id' => $booking->plot_id]);
    }
}
