<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RegistryAppointment;
use App\Models\RegistryCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RegistryAppointment> */
class RegistryAppointmentFactory extends Factory
{
    protected $model = RegistryAppointment::class;

    public function definition(): array
    {
        return [
            'registry_case_id' => RegistryCase::factory(),
            'scheduled_at' => now()->addWeek(),
            'registry_office' => 'Sub-Registrar Office '.fake()->city(),
            'appointment_reference' => fake()->bothify('APT-####'),
        ];
    }
}
