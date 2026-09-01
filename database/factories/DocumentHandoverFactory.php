<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HandoverStatus;
use App\Models\Booking;
use App\Models\DocumentHandover;
use App\Models\RegistryCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentHandover> */
class DocumentHandoverFactory extends Factory
{
    protected $model = DocumentHandover::class;

    public function definition(): array
    {
        $booking = Booking::factory()->confirmed();

        return [
            'booking_id' => $booking,
            'registry_case_id' => fn (array $a) => RegistryCase::factory()->create(['booking_id' => $a['booking_id']])->id,
            'status' => HandoverStatus::RegistryCompleted->value,
        ];
    }

    public function status(HandoverStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }
}
