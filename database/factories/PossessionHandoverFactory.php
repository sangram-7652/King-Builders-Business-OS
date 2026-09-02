<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PossessionHandoverStatus;
use App\Models\PossessionCase;
use App\Models\PossessionHandover;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PossessionHandover> */
class PossessionHandoverFactory extends Factory
{
    protected $model = PossessionHandover::class;

    public function definition(): array
    {
        return [
            'possession_case_id' => PossessionCase::factory(),
            'booking_id' => fn (array $a) => PossessionCase::find($a['possession_case_id'])?->booking_id,
            'status' => PossessionHandoverStatus::ReadyForHandover->value,
        ];
    }

    public function status(PossessionHandoverStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }
}
