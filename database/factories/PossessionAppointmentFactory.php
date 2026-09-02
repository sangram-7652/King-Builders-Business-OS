<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PossessionAppointment;
use App\Models\PossessionCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PossessionAppointment> */
class PossessionAppointmentFactory extends Factory
{
    protected $model = PossessionAppointment::class;

    public function definition(): array
    {
        return [
            'possession_case_id' => PossessionCase::factory(),
            'scheduled_at' => now()->addWeek(),
            'site_location' => 'Site office — '.fake()->city(),
            'notes' => null,
        ];
    }
}
