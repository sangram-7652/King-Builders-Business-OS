<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InspectionStatus;
use App\Models\PossessionCase;
use App\Models\PossessionInspection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PossessionInspection> */
class PossessionInspectionFactory extends Factory
{
    protected $model = PossessionInspection::class;

    public function definition(): array
    {
        return [
            'possession_case_id' => PossessionCase::factory(),
            'inspection_date' => now()->toDateString(),
            'status' => InspectionStatus::Pending->value,
            'remarks' => null,
        ];
    }

    public function status(InspectionStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }
}
