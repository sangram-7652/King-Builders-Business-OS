<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AgreementStatus;
use App\Enums\AgreementType;
use App\Models\Agreement;
use App\Models\Booking;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Agreement> */
class AgreementFactory extends Factory
{
    protected $model = Agreement::class;

    public function definition(): array
    {
        return [
            'agreement_number' => 'AGR-'.fake()->unique()->numerify('######'),
            'booking_id' => Booking::factory()->confirmed(),
            'type' => AgreementType::BookingAgreement->value,
            'status' => AgreementStatus::Draft->value,
        ];
    }

    public function status(AgreementStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    public function signed(): static
    {
        return $this->state(fn () => ['status' => AgreementStatus::Signed->value, 'signed_at' => now(), 'signed_by' => fake()->name()]);
    }
}
