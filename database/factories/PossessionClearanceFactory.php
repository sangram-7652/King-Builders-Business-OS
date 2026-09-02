<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ClearanceCategory;
use App\Enums\ClearanceStatus;
use App\Models\PossessionCase;
use App\Models\PossessionClearance;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PossessionClearance> */
class PossessionClearanceFactory extends Factory
{
    protected $model = PossessionClearance::class;

    public function definition(): array
    {
        return [
            'possession_case_id' => PossessionCase::factory(),
            'category' => ClearanceCategory::Financial->value,
            'status' => ClearanceStatus::Pending->value,
            'required' => true,
        ];
    }

    public function category(ClearanceCategory $category): static
    {
        return $this->state(fn () => ['category' => $category->value]);
    }

    public function cleared(): static
    {
        return $this->state(fn () => ['status' => ClearanceStatus::Cleared->value, 'decided_at' => now()]);
    }
}
